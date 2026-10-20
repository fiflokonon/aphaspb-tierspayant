<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A month without any invoice for an insurer is a real answer, not a gap:
     * until now the officine had no way to give it, so the month stayed « non
     * déclaré » on its dashboard and in the network's follow-up for good.
     *
     * Kept apart from `declarations` on purpose. A zero-amount declaration
     * would need a deposit date it does not have, and every aggregate that
     * counts or averages declarations would have to learn to skip it. This
     * table only feeds completeness.
     */
    public function up(): void
    {
        Schema::create('nil_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            // Same as declaration revisions: the author survives the account.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');
            $table->timestamps();

            $table->unique(['pharmacy_id', 'insurer_id', 'period_year', 'period_month'], 'nil_decl_pharmacy_insurer_period_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nil_declarations');
    }
};
