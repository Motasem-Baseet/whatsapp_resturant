<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * null = onboarding incomplete, a timestamp = complete (Phase 26) -
     * deliberately a plain nullable timestamp rather than a status
     * column/table, matching this app's existing `is_active` and
     * `email_verified_at` conventions for a simple two-state fact.
     *
     * Backward compatibility: a restaurant created before this column
     * existed must not be forced back into onboarding just because it
     * never explicitly "completed" it. Any restaurant that already has
     * at least one product, one order, or a configured WhatsApp account
     * clearly already went through real setup and is operating - it is
     * backfilled as already onboarded (onboarding_completed_at = now(),
     * the moment this column started existing). A restaurant with none
     * of that (e.g. a stale, never-used signup) is left null and will
     * see the onboarding flow, which is the correct behavior for it
     * regardless of how old the row is.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('logo_path');
        });

        $operationalRestaurantIds = DB::table('products')->pluck('restaurant_id')
            ->merge(DB::table('orders')->pluck('restaurant_id'))
            ->merge(DB::table('whatsapp_accounts')->pluck('restaurant_id'))
            ->unique();

        if ($operationalRestaurantIds->isNotEmpty()) {
            DB::table('restaurants')
                ->whereIn('id', $operationalRestaurantIds)
                ->update(['onboarding_completed_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn('onboarding_completed_at');
        });
    }
};
