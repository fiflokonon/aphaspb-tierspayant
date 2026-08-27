<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The two dates become the source of truth for the payment delay:
     * delay_days survives as a column because every network aggregate reads it
     * in SQL, but it is recomputed from this pair on every save.
     */
    public function up(): void
    {
        Schema::table('declarations', function (Blueprint $table) {
            $table->date('invoice_deposited_on')->nullable()->after('is_status_manual');
            $table->date('paid_on')->nullable()->after('invoice_deposited_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('declarations', function (Blueprint $table) {
            $table->dropColumn(['invoice_deposited_on', 'paid_on']);
        });
    }
};
