<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * L'association ne demande plus le numéro d'inscription à l'Ordre : le
     * champ quitte l'inscription, l'écran admin et la base.
     */
    public function up(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            $table->dropUnique(['onpb_license']);
            $table->dropColumn('onpb_license');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            $table->string('onpb_license', 50)->nullable()->unique()->after('slug');
        });
    }
};
