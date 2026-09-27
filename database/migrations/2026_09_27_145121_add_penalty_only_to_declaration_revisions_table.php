<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Une clôture de pénalité (payée, annulée, remise en dû) laisse une
     * révision, mais ce n'est pas une correction des chiffres déclarés. Le
     * drapeau la distingue : les compteurs « N corr. » / « Modifiée N fois » /
     * `corrections` de l'export l'excluent, l'historique la montre à part.
     *
     * Jamais vrai sur la première révision, qui est l'état d'origine.
     */
    public function up(): void
    {
        Schema::table('declaration_revisions', function (Blueprint $table) {
            $table->boolean('penalty_only')->default(false)->after('penalty_settled_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('declaration_revisions', function (Blueprint $table) {
            $table->dropColumn('penalty_only');
        });
    }
};
