<?php

namespace App\Actions\Declarations;

use App\Models\Declaration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Replace a declaration's instalments, then refresh what they feed.
 *
 * Rewritten wholesale rather than reconciled line by line: a declaration is a
 * monthly statement the officine corrects until it is right, not an audit
 * trail, and matching submitted lines to stored ones would need an identity
 * the form has no reason to carry.
 *
 * Wholesale is also what keeps the delays honest — correcting the deposit date
 * changes every instalment's delay, and recomputing them all is the only way
 * they cannot drift from the date they are measured against.
 */
class RecordPaymentInstalments
{
    /**
     * @param  list<array{amount: int, paid_on: string}>  $instalments
     */
    public function handle(Declaration $declaration, array $instalments): void
    {
        DB::transaction(function () use ($declaration, $instalments) {
            $declaration->payments()->delete();

            foreach ($instalments as $instalment) {
                $paidOn = CarbonImmutable::parse($instalment['paid_on'])->startOfDay();

                $declaration->payments()->create([
                    'amount' => $instalment['amount'],
                    'paid_on' => $paidOn,
                    'delay_days' => $declaration->deriveInstalmentDelayDays($paidOn),
                ]);
            }

            $declaration->syncFromInstalments();
        });
    }
}
