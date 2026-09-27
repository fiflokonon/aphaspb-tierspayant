<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La clôture d'une pénalité par l'officine : payée par l'assureur, ou
     * abandonnée. Quatre colonnes nullables, posées et levées ensemble par
     * Declaration::settlePenalty() / clearPenaltySettlement().
     *
     * `penalty_settled_amount` n'est pas un cache de la pénalité courue (qui
     * n'en a toujours pas) : c'est le montant constaté au moment du geste. Un
     * écart avec le calcul lève la clôture (ReconcilePenaltySettlement).
     *
     * La révision garde l'issue et le montant, pas l'auteur ni la date : elle
     * porte déjà les siens.
     */
    public function up(): void
    {
        Schema::table('declarations', function (Blueprint $table) {
            $table->string('penalty_settlement', 16)->nullable()->after('delay_days');
            $table->unsignedBigInteger('penalty_settled_amount')->nullable()->after('penalty_settlement');
            $table->date('penalty_settled_on')->nullable()->after('penalty_settled_amount');
            $table->foreignId('penalty_settled_by')->nullable()->after('penalty_settled_on')->constrained('users')->nullOnDelete();
        });

        Schema::table('declaration_revisions', function (Blueprint $table) {
            $table->string('penalty_settlement', 16)->nullable()->after('delay_days');
            $table->unsignedBigInteger('penalty_settled_amount')->nullable()->after('penalty_settlement');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('declaration_revisions', function (Blueprint $table) {
            $table->dropColumn(['penalty_settlement', 'penalty_settled_amount']);
        });

        Schema::table('declarations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('penalty_settled_by');
            $table->dropColumn(['penalty_settlement', 'penalty_settled_amount', 'penalty_settled_on']);
        });
    }
};
