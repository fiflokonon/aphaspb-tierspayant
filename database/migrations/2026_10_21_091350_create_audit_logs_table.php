<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Who did what, when, from where: writes, downloads and refused requests.
     * No request body, no query string — a declaration's amounts never land
     * here. Read in the database only; no screen shows it (CGU art. 9.1).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // The trace outlives the account and the officine it points to.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pharmacy_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10);
            $table->string('route_name')->nullable();
            $table->string('path', 2048);
            $table->unsignedSmallInteger('status');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
