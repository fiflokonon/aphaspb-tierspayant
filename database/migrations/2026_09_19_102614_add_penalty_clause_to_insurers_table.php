<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Une convention de tiers payant prévoit couramment une pénalité au-delà
     * d'un délai qui n'est pas celui du remboursement : un second seuil, plus
     * tardif, à partir duquel l'assureur doit majorer sa dette.
     *
     * Les deux colonnes sont nullables et **sans valeur par défaut** : tout
     * assureur existant sort d'ici sans clause, donc sans pénalité, et aucun
     * chiffre ne bouge le jour du déploiement. C'est l'inverse du choix fait
     * pour `standard_delay_days`, qui devait semer chaque ligne avec l'ancien
     * seuil global — ici il n'y a pas d'ancienne valeur à préserver, et un
     * défaut ferait apparaître des pénalités sur des conventions qui n'en
     * prévoient aucune.
     */
    public function up(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->unsignedSmallInteger('penalty_trigger_days')
                ->nullable()
                ->after('standard_delay_days');

            // En points de base plutôt qu'en décimal : une pénalité est de
            // l'argent, et tout montant de ce projet est un entier FCFA.
            // 250 = 2,50 %, et intdiv($base * 250, 10000) reste exact là où
            // $base * 2.5 / 100 passerait par un flottant.
            $table->unsignedSmallInteger('penalty_rate_bp')
                ->nullable()
                ->after('penalty_trigger_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->dropColumn(['penalty_trigger_days', 'penalty_rate_bp']);
        });
    }
};
