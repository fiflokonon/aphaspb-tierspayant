<?php

namespace App\Actions\Declarations;

use App\Models\Declaration;
use App\Models\DeclarationRevision;
use App\Models\User;

/**
 * Keep a snapshot of a declaration, unless it says exactly what the last one did.
 *
 * Called after the instalments are written, never before: a revision that
 * predated them would record the totals of the previous save and read as a
 * correction that never happened.
 */
class RecordDeclarationRevision
{
    public function handle(Declaration $declaration, User $author): ?DeclarationRevision
    {
        $snapshot = $this->snapshot($declaration);
        $previous = $declaration->revisions()->latest('id')->first();

        // Re-saving an untouched month is the commonest thing a pharmacist
        // does — reopening it to check a figure, then submitting. Recording
        // that would turn « modifiée 4 fois » into a count of visits.
        if ($previous !== null && $this->snapshot($previous) === $snapshot) {
            return null;
        }

        return $declaration->revisions()->create([
            ...$snapshot,
            'user_id' => $author->id,
            // Copied rather than joined: the trace has to stay readable after
            // the account is gone, and a name is what makes it readable.
            'author_name' => $author->name,
        ]);
    }

    /**
     * The comparable state of a declaration or of a stored revision.
     *
     * One method for both so the comparison cannot drift from what is written:
     * a field added to the snapshot is a field the equality test sees.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(Declaration|DeclarationRevision $subject): array
    {
        $payments = $subject instanceof Declaration
            ? $subject->payments->map(fn ($payment): array => [
                'amount' => $payment->amount,
                'paid_on' => $payment->paid_on->toDateString(),
                'delay_days' => $payment->delay_days,
            ])->all()
            : $subject->payments;

        return [
            'amount_invoiced' => $subject->amount_invoiced,
            'amount_received' => $subject->amount_received,
            'status' => $subject->status->value,
            'invoice_deposited_on' => $subject->invoice_deposited_on?->toDateString(),
            'paid_on' => $subject->paid_on?->toDateString(),
            'delay_days' => $subject->delay_days,
            'payments' => $payments,
        ];
    }
}
