<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Le numéro par lequel le réseau relance une officine dont la déclaration
     * d'un mois manque — relance faite hors de la plateforme, sur WhatsApp.
     *
     * Nullable et sans défaut : les officines déjà inscrites n'en ont pas, et
     * le champ reste facultatif. Rangé en E.164 (`+22997000000`) par
     * WhatsappNumber, ce qui tient en 16 caractères.
     */
    public function up(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            $table->string('whatsapp_phone', 20)->nullable()->after('owner_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pharmacies', function (Blueprint $table) {
            $table->dropColumn('whatsapp_phone');
        });
    }
};
