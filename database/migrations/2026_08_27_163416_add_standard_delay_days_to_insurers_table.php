<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** What the CDC sets when no threshold was ever recorded. */
    protected const FALLBACK_DAYS = 30;

    /**
     * Run the migrations.
     *
     * Each insurer now carries its own payment delay, which replaces the single
     * network-wide threshold: the existing global value seeds every row, so no
     * figure moves the day this runs.
     */
    public function up(): void
    {
        $threshold = (int) (DB::table('settings')
            ->where('key', 'payment_delay_threshold_days')
            ->value('value') ?? self::FALLBACK_DAYS);

        Schema::table('insurers', function (Blueprint $table) {
            $table->unsignedSmallInteger('standard_delay_days')
                ->default(self::FALLBACK_DAYS)
                ->after('is_active');
        });

        DB::table('insurers')->update(['standard_delay_days' => $threshold]);

        DB::table('settings')->where('key', 'payment_delay_threshold_days')->delete();

        Cache::forget('settings:payment_delay_threshold_days');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('insurers', function (Blueprint $table) {
            $table->dropColumn('standard_delay_days');
        });
    }
};
