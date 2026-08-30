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
        Schema::create('declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('insurer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->unsignedBigInteger('amount_invoiced')->default(0);
            $table->unsignedBigInteger('amount_received')->default(0);
            $table->string('status', 20);
            $table->boolean('is_status_manual')->default(false);
            $table->unsignedSmallInteger('delay_days')->nullable();
            $table->string('private_note', 150)->nullable();
            $table->timestamps();

            // $table->unique(['pharmacy_id', 'insurer_id', 'period_year', 'period_month']);
            $table->index(['insurer_id', 'period_year', 'period_month']);
$table->unique(
    ['pharmacy_id', 'insurer_id', 'period_year', 'period_month'],
    'decl_pharmacy_insurer_period_unique'
);
            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('declarations');
    }
};
