<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Restaurant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Historical order/revenue/product/customer analytics for a single
 * restaurant and date range. Every query starts from the given
 * Restaurant's own relationships (orders()/orderItems()/customers()) -
 * never a bare Order::query() - matching the same tenant-boundary
 * discipline established by GetDashboardMetrics.
 *
 * Deliberately separate from GetDashboardMetrics: the dashboard is
 * today-only/operational, this is date-range/historical - but both
 * apply the identical revenue rule (orders.total, cancelled excluded),
 * so financial reporting never contradicts the dashboard.
 *
 * $startDate/$endDate are received as already-resolved, inclusive
 * Carbon bounds (start-of-day / end-of-day) - resolving "today",
 * "last 7 days", a custom range, etc. into those bounds is the
 * caller's (Livewire component's) job, not this service's.
 */
class GetOrderReport
{
    /**
     * Beyond this many days in the selected range, revenue-over-time
     * switches from daily to weekly buckets - a year of daily points
     * would be both slow to render and unreadable as a table.
     */
    private const DAILY_GROUPING_LIMIT_DAYS = 62;

    private const TOP_LIST_LIMIT = 10;

    /**
     * @return array{
     *     summary: array{total_orders: int, completed_orders: int, cancelled_orders: int, revenue: string, average_order_value: string},
     *     status_breakdown: list<array{status: OrderStatus, label: string, count: int, percentage: float}>,
     *     revenue_over_time: list<array{label: string, revenue: string}>,
     *     top_products: Collection,
     *     top_customers: Collection,
     *     new_customers: int,
     * }
     */
    public function handle(Restaurant $restaurant, Carbon $startDate, Carbon $endDate): array
    {
        return [
            'summary' => $this->summary($restaurant, $startDate, $endDate),
            'status_breakdown' => $this->statusBreakdown($restaurant, $startDate, $endDate),
            'revenue_over_time' => $this->revenueOverTime($restaurant, $startDate, $endDate),
            'top_products' => $this->topProducts($restaurant, $startDate, $endDate),
            'top_customers' => $this->topCustomers($restaurant, $startDate, $endDate),
            'new_customers' => $restaurant->customers()->whereBetween('created_at', [$startDate, $endDate])->count(),
        ];
    }

    private function ordersInRange(Restaurant $restaurant, Carbon $startDate, Carbon $endDate)
    {
        return $restaurant->orders()->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * @return array{total_orders: int, completed_orders: int, cancelled_orders: int, revenue: string, average_order_value: string}
     */
    private function summary(Restaurant $restaurant, Carbon $startDate, Carbon $endDate): array
    {
        $totalOrders = $this->ordersInRange($restaurant, $startDate, $endDate)->count();
        $completedOrders = $this->ordersInRange($restaurant, $startDate, $endDate)
            ->where('status', OrderStatus::Completed->value)->count();
        $cancelledOrders = $this->ordersInRange($restaurant, $startDate, $endDate)
            ->where('status', OrderStatus::Cancelled->value)->count();

        // Same rule as GetDashboardMetrics::forOwnerOrCashier() -
        // orders.total is authoritative and already server-calculated
        // at creation time (see CreateOrder); cancelled orders are the
        // only ones excluded, every other status counts.
        $revenue = (float) $this->ordersInRange($restaurant, $startDate, $endDate)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->sum('total');

        $nonCancelledOrders = $totalOrders - $cancelledOrders;

        return [
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'revenue' => number_format($revenue, 2, '.', ''),
            'average_order_value' => $nonCancelledOrders > 0
                ? number_format($revenue / $nonCancelledOrders, 2, '.', '')
                : '0.00',
        ];
    }

    /**
     * Every OrderStatus case is represented, even at zero, so the UI
     * never has to guess which statuses are "applicable" - the enum
     * itself is the only source of truth for what a status is called.
     *
     * @return list<array{status: OrderStatus, label: string, count: int, percentage: float}>
     */
    private function statusBreakdown(Restaurant $restaurant, Carbon $startDate, Carbon $endDate): array
    {
        $counts = $this->ordersInRange($restaurant, $startDate, $endDate)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = (int) $counts->sum();

        return collect(OrderStatus::cases())
            ->map(function (OrderStatus $status) use ($counts, $total) {
                $count = (int) ($counts[$status->value] ?? 0);

                return [
                    'status' => $status,
                    'label' => $status->label(),
                    'count' => $count,
                    'percentage' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                ];
            })
            ->all();
    }

    /**
     * A single grouped aggregate query for daily revenue (DATE() is
     * portable across the MySQL/SQLite connections this app runs on,
     * unlike MySQL-only functions such as FIELD()), then folded in PHP
     * into either one point per day or one point per 7-day bucket -
     * never a query per day/bucket.
     *
     * @return list<array{label: string, revenue: string}>
     */
    private function revenueOverTime(Restaurant $restaurant, Carbon $startDate, Carbon $endDate): array
    {
        $dailyRevenue = $this->ordersInRange($restaurant, $startDate, $endDate)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('DATE(created_at) as day, SUM(total) as revenue')
            ->groupBy('day')
            ->pluck('revenue', 'day');

        $totalDays = $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1;
        $bucketDays = $totalDays <= self::DAILY_GROUPING_LIMIT_DAYS ? 1 : 7;

        $points = [];
        $cursor = $startDate->copy()->startOfDay();

        while ($cursor->lte($endDate)) {
            $bucketEnd = $cursor->copy()->addDays($bucketDays - 1);
            if ($bucketEnd->gt($endDate)) {
                $bucketEnd = $endDate->copy()->startOfDay();
            }

            $revenue = 0.0;
            $day = $cursor->copy();
            while ($day->lte($bucketEnd)) {
                $revenue += (float) ($dailyRevenue[$day->format('Y-m-d')] ?? 0);
                $day->addDay();
            }

            $points[] = [
                'label' => $bucketDays === 1
                    ? $cursor->format('Y-m-d')
                    : $cursor->format('Y-m-d').' – '.$bucketEnd->format('Y-m-d'),
                'revenue' => number_format($revenue, 2, '.', ''),
            ];

            $cursor->addDays($bucketDays);
        }

        return $points;
    }

    /**
     * Grouped by the order item's own historical product_name/price
     * snapshot - never joined against the live products table - so a
     * renamed, repriced, deactivated, or deleted product never changes
     * what a past report shows. Cancelled orders' items are excluded,
     * matching the same "cancelled doesn't count" rule applied to
     * revenue elsewhere. One aggregate query total (whereHas compiles
     * to a WHERE EXISTS subquery, not a second round-trip).
     */
    private function topProducts(Restaurant $restaurant, Carbon $startDate, Carbon $endDate): Collection
    {
        return $restaurant->orderItems()
            ->whereHas('order', function ($query) use ($startDate, $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate])
                    ->where('status', '!=', OrderStatus::Cancelled->value);
            })
            ->selectRaw('product_name, SUM(quantity) as total_quantity, SUM(line_total) as total_revenue, COUNT(DISTINCT order_id) as order_count')
            ->groupBy('product_name')
            ->orderByDesc('total_quantity')
            ->limit(self::TOP_LIST_LIMIT)
            ->get();
    }

    /**
     * withCount()/withSum() with a constrained closure compile to
     * correlated subqueries in the customers SELECT - one query total,
     * never a query per customer. Customers with no orders in the
     * range are excluded via whereHas() (a WHERE EXISTS clause) rather
     * than HAVING on the withCount/withSum alias - SQLite (unlike
     * MySQL) rejects a HAVING clause referencing a subquery-derived
     * column with no GROUP BY, so whereHas() is both the portable and
     * the simpler choice here.
     */
    private function topCustomers(Restaurant $restaurant, Carbon $startDate, Carbon $endDate): Collection
    {
        $scope = function ($query) use ($startDate, $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate])
                ->where('status', '!=', OrderStatus::Cancelled->value);
        };

        return $restaurant->customers()
            ->whereHas('orders', $scope)
            ->withCount(['orders' => $scope])
            ->withSum(['orders' => $scope], 'total')
            ->orderByDesc('orders_sum_total')
            ->limit(self::TOP_LIST_LIMIT)
            ->get();
    }
}
