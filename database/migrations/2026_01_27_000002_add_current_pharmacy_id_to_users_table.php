<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // No ->after(): the column it used to follow was the password,
            // dropped with Fortify. SQLite ignores the clause, so the stale
            // reference passed the tests while breaking MySQL and Postgres.
            $table->foreignId('current_pharmacy_id')
                ->nullable()
                ->constrained('pharmacies')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_pharmacy_id');
        });
    }
};
