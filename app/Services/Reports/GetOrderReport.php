<?php

namespace App\Services\Reports;

use App\Enums\OrderStatus;
use App\Models\Restaurant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Historical order/revenue/product/customer/operational analytics for
 * a single restaurant and date range. Every query starts from the
 * given Restaurant's own relationships (orders()/orderItems()/
 * customers()/orderStatusHistories()) - never a bare Order::query() -
 * matching the same tenant-boundary discipline established by
 * GetDashboardMetrics.
 *
 * Deliberately separate from GetDashboardMetrics: the dashboard is
 * today-only/operational, this is date-range/historical - but both
 * apply the identical revenue rule (orders.total, cancelled excluded),
 * so financial reporting never contradicts the dashboard.
 *
 * $startDate/$endDate are received as already-resolved, inclusive
 * Carbon bounds (start-of-day / end-of-day) - resolving "today",
 * "last 7 days", a custom range, etc. into those bounds is the
 * caller's (Livewire component's) job, not this service's. Every new
 * (Phase 24) metric uses these exact same bounds, so "the selected
 * period" means one consistent thing across the whole report.
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
     *     revenue_over_time: list<array{label: string, revenue: string, order_count: int, unit: string}>,
     *     best_period: array{label: string, revenue: string, order_count: int, unit: string}|null,
     *     top_products: Collection,
     *     top_customers: Collection,
     *     new_customers: int,
     *     comparison: array{previous_period: array{start: Carbon, end: Carbon}, metrics: array<string, array{current: mixed, previous: mixed, change: float, percentage_change: float|null}>},
     *     customer_retention: array{new_customers: int, returning_customers: int, repeat_customers: int, customers_with_orders: int},
     *     operational_performance: array{avg_pending_to_confirmed_minutes: float|null, avg_confirmed_to_preparing_minutes: float|null, avg_preparing_to_ready_minutes: float|null, avg_fulfillment_minutes: float|null, completed_sample_size: int},
     * }
     */
    public function handle(Restaurant $restaurant, Carbon $startDate, Carbon $endDate): array
    {
        $statusCounts = $this->statusCounts($restaurant, $startDate, $endDate);
        $summary = $this->summary($restaurant, $startDate, $endDate, $statusCounts);
        $dailyAggregates = $this->dailyAggregates($restaurant, $startDate, $endDate);
        $revenueOverTime = $this->revenueOverTimePoints($dailyAggregates, $startDate, $endDate);
        $newCustomers = $restaurant->customers()->whereBetween('created_at', [$startDate, $endDate])->count();

        return [
            'summary' => $summary,
            'status_breakdown' => $this->statusBreakdown($statusCounts),
            'revenue_over_time' => $revenueOverTime,
            'best_period' => $this->bestPeriod($revenueOverTime),
            'top_products' => $this->topProducts($restaurant, $startDate, $endDate, (float) $summary['revenue']),
            'top_customers' => $this->topCustomers($restaurant, $startDate, $endDate),
            'new_customers' => $newCustomers,
            'comparison' => $this->comparison($restaurant, $startDate, $endDate, $summary, $newCustomers),
            'customer_retention' => $this->customerRetention($restaurant, $startDate, $endDate, $newCustomers),
            'operational_performance' => $this->operationalPerformance($restaurant, $startDate, $endDate),
        ];
    }

    private function ordersInRange(Restaurant $restaurant, Carbon $startDate, Carbon $endDate)
    {
        return $restaurant->orders()->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * One grouped query, reused by both summary() (for total/completed/
     * cancelled counts) and statusBreakdown() - avoids three separate
     * COUNT(*) queries that statusBreakdown's own grouped query already
     * makes redundant.
     */
    private function statusCounts(Restaurant $restaurant, Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->ordersInRange($restaurant, $startDate, $endDate)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
    }

    /**
     * @return array{total_orders: int, completed_orders: int, cancelled_orders: int, revenue: string, average_order_value: string}
     */
    private function summary(Restaurant $restaurant, Carbon $startDate, Carbon $endDate, Collection $statusCounts): array
    {
        $totalOrders = (int) $statusCounts->sum();
        $completedOrders = (int) ($statusCounts[OrderStatus::Completed->value] ?? 0);
        $cancelledOrders = (int) ($statusCounts[OrderStatus::Cancelled->value] ?? 0);

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
    private function statusBreakdown(Collection $statusCounts): array
    {
        $total = (int) $statusCounts->sum();

        return collect(OrderStatus::cases())
            ->map(function (OrderStatus $status) use ($statusCounts, $total) {
                $count = (int) ($statusCounts[$status->value] ?? 0);

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
     * One grouped aggregate query for daily revenue AND order count
     * (DATE() is portable across the MySQL/SQLite connections this app
     * runs on, unlike MySQL-only functions such as FIELD()) - shared by
     * revenueOverTimePoints() below, so discovering the best day/bucket
     * never requires a second query purely to find a maximum.
     */
    private function dailyAggregates(Restaurant $restaurant, Carbon $startDate, Carbon $endDate): Collection
    {
        return $this->ordersInRange($restaurant, $startDate, $endDate)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('DATE(created_at) as day, SUM(total) as revenue, COUNT(*) as order_count')
            ->groupBy('day')
            ->get()
            ->keyBy('day');
    }

    /**
     * Folds the single daily-aggregates query into either one point per
     * day or one point per 7-day bucket, in PHP - never a query per
     * day/bucket. Every point carries its own 'unit' so a weekly bucket
     * is never mislabelled as a day.
     *
     * @return list<array{label: string, revenue: string, order_count: int, unit: string}>
     */
    private function revenueOverTimePoints(Collection $dailyAggregates, Carbon $startDate, Carbon $endDate): array
    {
        $totalDays = $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1;
        $bucketDays = $totalDays <= self::DAILY_GROUPING_LIMIT_DAYS ? 1 : 7;
        $unit = $bucketDays === 1 ? 'day' : 'week';

        $points = [];
        $cursor = $startDate->copy()->startOfDay();

        while ($cursor->lte($endDate)) {
            $bucketEnd = $cursor->copy()->addDays($bucketDays - 1);
            if ($bucketEnd->gt($endDate)) {
                $bucketEnd = $endDate->copy()->startOfDay();
            }

            $revenue = 0.0;
            $orderCount = 0;
            $day = $cursor->copy();
            while ($day->lte($bucketEnd)) {
                $key = $day->format('Y-m-d');
                $revenue += (float) ($dailyAggregates[$key]->revenue ?? 0);
                $orderCount += (int) ($dailyAggregates[$key]->order_count ?? 0);
                $day->addDay();
            }

            $points[] = [
                'label' => $unit === 'day'
                    ? $cursor->format('Y-m-d')
                    : $cursor->format('Y-m-d').' – '.$bucketEnd->format('Y-m-d'),
                'revenue' => number_format($revenue, 2, '.', ''),
                'order_count' => $orderCount,
                'unit' => $unit,
            ];

            $cursor->addDays($bucketDays);
        }

        return $points;
    }

    /**
     * The highest-revenue point already computed by
     * revenueOverTimePoints() - no duplicate query. Null when every
     * point is zero (nothing meaningful to call "best" in an empty
     * period), rather than pointing at an arbitrary zero-revenue day.
     *
     * @param  list<array{label: string, revenue: string, order_count: int, unit: string}>  $revenueOverTime
     * @return array{label: string, revenue: string, order_count: int, unit: string}|null
     */
    private function bestPeriod(array $revenueOverTime): ?array
    {
        if (empty($revenueOverTime)) {
            return null;
        }

        $best = collect($revenueOverTime)->sortByDesc(fn (array $point) => (float) $point['revenue'])->first();

        return (float) $best['revenue'] > 0.0 ? $best : null;
    }

    /**
     * Grouped by the order item's own historical product_name/price
     * snapshot - never joined against the live products table - so a
     * renamed, repriced, deactivated, or deleted product never changes
     * what a past report shows. Cancelled orders' items are excluded,
     * matching the same "cancelled doesn't count" rule applied to
     * revenue elsewhere. One aggregate query total (whereHas compiles
     * to a WHERE EXISTS subquery, not a second round-trip);
     * revenue_percentage is computed in PHP against the already-known
     * period revenue total, not a second query.
     */
    private function topProducts(Restaurant $restaurant, Carbon $startDate, Carbon $endDate, float $periodRevenue): Collection
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
            ->get()
            ->each(function ($product) use ($periodRevenue) {
                $product->revenue_percentage = $periodRevenue > 0
                    ? round(((float) $product->total_revenue / $periodRevenue) * 100, 1)
                    : 0.0;
            });
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

    /**
     * The immediately preceding period of the exact same duration,
     * ending the instant before the current period starts - applied
     * uniformly to every preset (today/7 days/30 days/this
     * month/custom) rather than special-casing "this month" to mean
     * the previous calendar month. This keeps period comparison fully
     * deterministic from the resolved [start, end] bounds alone (a
     * 31-day "this month" is compared against the preceding 31 days,
     * not strictly the previous calendar month, which may be shorter).
     *
     * Duration is measured in whole days via startOfDay()-normalized
     * bounds (the same technique revenueOverTimePoints() uses for
     * totalDays above) rather than diffInSeconds() on the raw bounds -
     * $endDate carries endOfDay()'s trailing .999999 microseconds,
     * which previously rounded a whole-day duration up to one second
     * over a day boundary and shifted the previous period an extra day
     * too far back.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function previousPeriodBounds(Carbon $startDate, Carbon $endDate): array
    {
        $totalDays = $startDate->copy()->startOfDay()->diffInDays($endDate->copy()->startOfDay()) + 1;

        $previousEnd = $startDate->copy()->subDay()->endOfDay();
        $previousStart = $startDate->copy()->subDays($totalDays)->startOfDay();

        return [$previousStart, $previousEnd];
    }

    /**
     * Never returns Infinity/NaN. Previous = 0 and current = 0 is a
     * truthful 0% (no change); previous = 0 and current > 0 has no
     * mathematically valid percentage (an infinite increase) and
     * returns null so the UI can render "New"/"—" instead of a
     * fabricated number.
     */
    private function percentageChange(float $previous, float $current): ?float
    {
        if ($previous === 0.0) {
            return $current === 0.0 ? 0.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * Compares the current period against the immediately preceding
     * one of equal duration across revenue, total orders, completed
     * orders, average order value, and new customers. Reuses the
     * already-computed current summary/new-customers rather than
     * recomputing them - only the previous period's equivalents are
     * freshly queried.
     */
    private function comparison(Restaurant $restaurant, Carbon $startDate, Carbon $endDate, array $currentSummary, int $currentNewCustomers): array
    {
        [$previousStart, $previousEnd] = $this->previousPeriodBounds($startDate, $endDate);

        $previousSummary = $this->summary(
            $restaurant,
            $previousStart,
            $previousEnd,
            $this->statusCounts($restaurant, $previousStart, $previousEnd),
        );
        $previousNewCustomers = $restaurant->customers()->whereBetween('created_at', [$previousStart, $previousEnd])->count();

        $pairs = [
            'revenue' => [(float) $currentSummary['revenue'], (float) $previousSummary['revenue']],
            'total_orders' => [$currentSummary['total_orders'], $previousSummary['total_orders']],
            'completed_orders' => [$currentSummary['completed_orders'], $previousSummary['completed_orders']],
            'average_order_value' => [(float) $currentSummary['average_order_value'], (float) $previousSummary['average_order_value']],
            'new_customers' => [$currentNewCustomers, $previousNewCustomers],
        ];

        $metrics = [];
        foreach ($pairs as $key => [$current, $previous]) {
            $metrics[$key] = [
                'current' => $current,
                'previous' => $previous,
                'change' => $current - $previous,
                'percentage_change' => $this->percentageChange((float) $previous, (float) $current),
            ];
        }

        return [
            'previous_period' => ['start' => $previousStart, 'end' => $previousEnd],
            'metrics' => $metrics,
        ];
    }

    /**
     * Business definitions (deliberately explicit, since none of these
     * are given by the domain itself):
     *  - New customers: created_at falls inside the selected period.
     *  - Customers with orders: placed at least one non-cancelled order
     *    in the period.
     *  - Returning customers: placed a non-cancelled order in the
     *    period AND had already placed a non-cancelled order before
     *    the period started.
     *  - Repeat customers: placed two or more non-cancelled orders
     *    within the period itself.
     * Cancelled orders are excluded from all of these, consistent with
     * "cancelled doesn't count as real activity" applied everywhere
     * else in this report. Four small COUNT-based queries, no
     * customer/order rows loaded into PHP.
     */
    private function customerRetention(Restaurant $restaurant, Carbon $startDate, Carbon $endDate, int $newCustomers): array
    {
        $inPeriod = function ($query) use ($startDate, $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate])
                ->where('status', '!=', OrderStatus::Cancelled->value);
        };

        $beforePeriod = function ($query) use ($startDate) {
            $query->where('created_at', '<', $startDate)
                ->where('status', '!=', OrderStatus::Cancelled->value);
        };

        $customersWithOrders = $restaurant->customers()->whereHas('orders', $inPeriod)->count();

        $returningCustomers = $restaurant->customers()
            ->whereHas('orders', $inPeriod)
            ->whereHas('orders', $beforePeriod)
            ->count();

        $repeatCustomers = $restaurant->customers()
            ->whereHas('orders', $inPeriod, '>=', 2)
            ->count();

        return [
            'new_customers' => $newCustomers,
            'returning_customers' => $returningCustomers,
            'repeat_customers' => $repeatCustomers,
            'customers_with_orders' => $customersWithOrders,
        ];
    }

    /**
     * Average time spent in each stage of the lifecycle, derived
     * entirely from order_status_histories.created_at timestamps -
     * no new event/table, no fabricated timestamps. Scoped to orders
     * *created* within the selected period (matching every other
     * metric's own period boundary), fetched in one query, then paired
     * up in PHP per order_id rather than a portable-but-fragile
     * cross-database date-diff aggregate (MySQL/SQLite disagree on the
     * function for that).
     *
     * An order only contributes to a given average if it actually has
     * both the "from" and "to" timestamps needed for that specific
     * transition - a cancelled-after-confirming order still
     * contributes its (real) pending->confirmed duration, but never a
     * confirmed->preparing duration it never reached. Fulfillment time
     * (creation to completion) only counts orders that actually reached
     * Completed. This is exactly the "don't distort with incomplete
     * lifecycles" rule the phase requires - it falls out naturally from
     * only measuring transitions that are actually present, not from
     * special-casing cancellation.
     */
    private function operationalPerformance(Restaurant $restaurant, Carbon $startDate, Carbon $endDate): array
    {
        $histories = $restaurant->orderStatusHistories()
            ->whereHas('order', fn ($query) => $query->whereBetween('created_at', [$startDate, $endDate]))
            ->with('order:id,created_at')
            ->get(['id', 'order_id', 'from_status', 'to_status', 'created_at']);

        $pendingToConfirmed = [];
        $confirmedToPreparing = [];
        $preparingToReady = [];
        $fulfillment = [];

        foreach ($histories->groupBy('order_id') as $rows) {
            $orderCreatedAt = $rows->first()->order->created_at;
            $confirmedAt = $rows->firstWhere('to_status', OrderStatus::Confirmed->value)?->created_at;
            $preparingAt = $rows->firstWhere('to_status', OrderStatus::Preparing->value)?->created_at;
            $readyAt = $rows->firstWhere('to_status', OrderStatus::Ready->value)?->created_at;
            $completedAt = $rows->firstWhere('to_status', OrderStatus::Completed->value)?->created_at;

            if ($confirmedAt !== null) {
                $pendingToConfirmed[] = $orderCreatedAt->diffInSeconds($confirmedAt);
            }
            if ($confirmedAt !== null && $preparingAt !== null) {
                $confirmedToPreparing[] = $confirmedAt->diffInSeconds($preparingAt);
            }
            if ($preparingAt !== null && $readyAt !== null) {
                $preparingToReady[] = $preparingAt->diffInSeconds($readyAt);
            }
            if ($completedAt !== null) {
                $fulfillment[] = $orderCreatedAt->diffInSeconds($completedAt);
            }
        }

        return [
            'avg_pending_to_confirmed_minutes' => $this->averageMinutes($pendingToConfirmed),
            'avg_confirmed_to_preparing_minutes' => $this->averageMinutes($confirmedToPreparing),
            'avg_preparing_to_ready_minutes' => $this->averageMinutes($preparingToReady),
            'avg_fulfillment_minutes' => $this->averageMinutes($fulfillment),
            'completed_sample_size' => count($fulfillment),
        ];
    }

    /**
     * Null (not zero) when there is no data at all - "no data" and
     * "zero minutes" are different facts, and conflating them would
     * misrepresent an empty period as instant service.
     *
     * @param  list<int>  $secondsList
     */
    private function averageMinutes(array $secondsList): ?float
    {
        if (empty($secondsList)) {
            return null;
        }

        return round((array_sum($secondsList) / count($secondsList)) / 60, 1);
    }
}
