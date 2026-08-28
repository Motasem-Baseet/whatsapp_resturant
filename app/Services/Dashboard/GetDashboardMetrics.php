<?php

namespace App\Services\Dashboard;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;

/**
 * Read-only aggregate queries for the dashboard. Restaurant (and User,
 * where the metric is user-specific) are received explicitly rather
 * than resolved from TenantContext - the same lesson already applied by
 * CreateOrder/CreateMessage/etc: this keeps every query correct
 * regardless of whether the calling context happens to have run
 * IdentifyTenant, and makes the tenant boundary visible at every call
 * site rather than ambient.
 *
 * Every query starts from $restaurant's own relationships
 * (orders()/customers()/conversations()), never a bare Order::query()/
 * Customer::query()/Conversation::query() - so even if the underlying
 * BelongsToRestaurant global scope were ever bypassed, these queries
 * would still be explicitly bounded to the given restaurant.
 */
class GetDashboardMetrics
{
    /**
     * @return array{
     *     todays_orders: int,
     *     todays_revenue: string,
     *     pending_orders: int,
     *     active_kitchen_orders: int,
     *     todays_new_customers: int,
     *     unread_conversations: int,
     *     unread_conversation_ids: list<int>,
     *     attention_orders_count: int,
     *     recent_orders: \Illuminate\Support\Collection,
     *     recent_conversations: \Illuminate\Support\Collection,
     * }
     */
    public function forOwnerOrCashier(Restaurant $restaurant, User $user): array
    {
        // Computed once and reused for both the count and the per-row
        // "unread" indicator on the recent-conversations list below,
        // rather than running unreadFor() twice or checking membership
        // with a query per row.
        $unreadConversationIds = $restaurant->conversations()
            ->unreadFor($user)
            ->pluck('conversations.id')
            ->all();

        return [
            'todays_orders' => $restaurant->orders()
                ->whereDate('created_at', today())
                ->count(),

            // orders.total is the authoritative, already-calculated
            // decimal column (see CreateOrder) - summed directly rather
            // than recomputed from order_items, and excluding cancelled
            // orders per the spec. SUM() over a DECIMAL column is exact
            // in both MySQL and SQLite (no float drift), so no
            // integer-cents conversion is needed for the arithmetic
            // itself - but SQLite's dynamic typing returns a plain
            // numeric string ("25.5", not "25.50") where MySQL would
            // preserve two decimal places, so the result is normalized
            // through number_format() to a stable "0.00"-style string
            // regardless of driver, matching this app's existing money
            // display convention.
            'todays_revenue' => number_format(
                (float) $restaurant->orders()
                    ->whereDate('created_at', today())
                    ->where('status', '!=', OrderStatus::Cancelled->value)
                    ->sum('total'),
                2,
                '.',
                ''
            ),

            'pending_orders' => $restaurant->orders()
                ->where('status', OrderStatus::Pending->value)
                ->count(),

            'active_kitchen_orders' => $restaurant->orders()
                ->whereIn('status', [
                    OrderStatus::Confirmed->value,
                    OrderStatus::Preparing->value,
                    OrderStatus::Ready->value,
                ])
                ->count(),

            'todays_new_customers' => $restaurant->customers()
                ->whereDate('created_at', today())
                ->count(),

            'unread_conversations' => count($unreadConversationIds),
            'unread_conversation_ids' => $unreadConversationIds,

            // Bounded to the restaurant's currently-active orders only
            // (never all-time history), then classified in PHP via
            // Order::requiresAttention() - the same authoritative method
            // the order list and detail pages use - so this count can
            // never drift from what those pages actually show.
            'attention_orders_count' => $restaurant->orders()
                ->whereIn('status', Order::attentionEligibleStatusValues())
                ->with('statusHistory')
                ->get()
                ->filter(fn (Order $order) => $order->requiresAttention())
                ->count(),

            'recent_orders' => $restaurant->orders()
                ->with('customer')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(),

            'recent_conversations' => $restaurant->conversations()
                ->with(['customer', 'assignedUser'])
                ->orderByDesc('last_message_at')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(),
        ];
    }

    /**
     * @return array{
     *     confirmed_count: int,
     *     preparing_count: int,
     *     ready_count: int,
     *     attention_orders_count: int,
     *     recent_orders: \Illuminate\Support\Collection,
     * }
     */
    public function forKitchen(Restaurant $restaurant): array
    {
        return [
            'confirmed_count' => $restaurant->orders()
                ->where('status', OrderStatus::Confirmed->value)
                ->count(),

            'preparing_count' => $restaurant->orders()
                ->where('status', OrderStatus::Preparing->value)
                ->count(),

            'ready_count' => $restaurant->orders()
                ->where('status', OrderStatus::Ready->value)
                ->count(),

            // Confirmed/preparing only - never ready, matching the
            // kitchen order list's own treatment (kitchen has no action
            // available on a ready order, so it is never surfaced to
            // kitchen as something needing kitchen attention).
            'attention_orders_count' => $restaurant->orders()
                ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Preparing->value])
                ->with('statusHistory')
                ->get()
                ->filter(fn (Order $order) => $order->requiresAttention())
                ->count(),

            // Same kitchen-relevant status set as
            // livewire/kitchen/orders/index.blade.php - never pending,
            // completed, or cancelled.
            'recent_orders' => $restaurant->orders()
                ->with('customer')
                ->whereIn('status', [
                    OrderStatus::Confirmed->value,
                    OrderStatus::Preparing->value,
                    OrderStatus::Ready->value,
                ])
                ->orderByDesc('created_at')
                ->limit(5)
                ->get(),
        ];
    }
}
