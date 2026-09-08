<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An insurer rarely settles a month in one go: it pays a share, then the
     * rest weeks later. Until now a declaration could only record the pair
     * (amount_received, paid_on), which forced the officine to flatten several
     * transfers into one and lose the dates the delay is measured from.
     *
     * The two columns survive on `declarations` as caches — every network
     * aggregate reads them in SQL — but this table becomes their source: the
     * total is the sum of these rows, and the declaration's payment date is
     * the most recent of them.
     */
    public function up(): void
    {
        Schema::create('declaration_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('declaration_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            // Stored for the same reason as declarations.delay_days: the share
            // of money recovered within an insurer's standard delay is summed
            // in SQL, and comparing a date to an interval held in a column
            // would mean date arithmetic this project keeps out of its queries.
            $table->unsignedSmallInteger('delay_days')->nullable();
            $table->timestamps();

            $table->index(['declaration_id', 'paid_on']);
        });

        // Every declaration already settled becomes a single instalment, so no
        // figure moves the day this ships: the sum of one row is the old total,
        // and its date is the old payment date.
        DB::table('declarations')
            ->select('id', 'amount_received', 'paid_on', 'delay_days')
            ->where('amount_received', '>', 0)
            ->whereNotNull('paid_on')
            ->orderBy('id')
            ->chunk(500, function ($declarations) {
                DB::table('declaration_payments')->insert(
                    $declarations->map(fn (object $declaration): array => [
                        'declaration_id' => $declaration->id,
                        'amount' => $declaration->amount_received,
                        'paid_on' => $declaration->paid_on,
                        'delay_days' => $declaration->delay_days,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all(),
                );
            });
    }

    /**
     * Reverse the migrations.
     *
     * The declarations keep their cached total and payment date, so dropping
     * this table loses the breakdown of a settlement, never the settlement.
     */
    public function down(): void
    {
        Schema::dropIfExists('declaration_payments');
    }
};
