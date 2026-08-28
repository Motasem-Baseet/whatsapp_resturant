<?php

namespace Tests\Feature\Reports;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Reports\GetOrderReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Order reporting/analytics (Phase 23). GetOrderReport is tested
 * directly with explicit Carbon bounds for aggregation correctness
 * (precise, no real-clock ambiguity); the Livewire page's own date
 * range *resolution* (today/7days/custom/etc into concrete bounds) is
 * tested separately since that logic lives in the component, not the
 * service.
 */
class OrderReportingTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(Restaurant $restaurant): User
    {
        $owner = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $owner->assignRole(Role::findOrCreate('owner'));

        return $owner;
    }

    private function createEmployee(Restaurant $restaurant, string $role): User
    {
        $employee = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $employee->assignRole(Role::findOrCreate($role));

        return $employee;
    }

    private function createOrder(
        Restaurant $restaurant,
        OrderStatus $status,
        string $total,
        ?Carbon $createdAt = null,
        ?Customer $customer = null,
    ): Order {
        $customer ??= Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $order = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'total' => $total,
        ]);

        if ($createdAt !== null) {
            $order->created_at = $createdAt;
            $order->save();
        }

        return $order;
    }

    // --- A. Authorization ----------------------------------------------------

    public function test_owner_can_access_the_reports_page(): void
    {
        $owner = $this->createOwner(Restaurant::factory()->create());

        $this->actingAs($owner)->get(route('reports.orders'))->assertOk();
    }

    public function test_cashier_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier)->get(route('reports.orders'))->assertForbidden();
    }

    public function test_kitchen_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen)->get(route('reports.orders'))->assertForbidden();
    }

    public function test_a_roleless_user_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $roleless = User::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($roleless)->get(route('reports.orders'))->assertForbidden();
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->get(route('reports.orders'))->assertRedirect(route('login'));
    }

    // --- B. Date range resolution (component-level) --------------------------

    public function test_today_range_resolves_to_the_current_calendar_day(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 15, 10, 0));
        $owner = $this->createOwner(Restaurant::factory()->create());
        $this->actingAs($owner);

        [$start, $end] = Volt::test('reports.orders')->instance()->dateRange();
        $this->travelBack();

        $this->assertSame('2026-06-15 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_last_7_days_range_is_inclusive_of_today_and_6_days_back(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 15));
        $owner = $this->createOwner(Restaurant::factory()->create());
        $this->actingAs($owner);

        [$start, $end] = Volt::test('reports.orders')->set('range', '7days')->instance()->dateRange();
        $this->travelBack();

        $this->assertSame('2026-06-09', $start->format('Y-m-d'));
        $this->assertSame('2026-06-15', $end->format('Y-m-d'));
    }

    public function test_last_30_days_range_is_inclusive_of_today_and_29_days_back(): void
    {
        $this->travelTo(Carbon::create(2026, 6, 15));
        $owner = $this->createOwner(Restaurant::factory()->create());
        $this->actingAs($owner);

        [$start, $end] = Volt::test('reports.orders')->set('range', '30days')->instance()->dateRange();
        $this->travelBack();

        $this->assertSame('2026-05-17', $start->format('Y-m-d'));
        $this->assertSame('2026-06-15', $end->format('Y-m-d'));
    }

    public function test_custom_range_resolves_to_the_given_start_and_end_dates(): void
    {
        $owner = $this->createOwner(Restaurant::factory()->create());
        $this->actingAs($owner);

        [$start, $end] = Volt::test('reports.orders')
            ->set('range', 'custom')
            ->set('customStart', '2026-01-01')
            ->set('customEnd', '2026-01-10')
            ->instance()
            ->dateRange();

        $this->assertSame('2026-01-01 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-10 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_a_single_day_custom_range_is_handled_correctly(): void
    {
        $owner = $this->createOwner(Restaurant::factory()->create());
        $this->actingAs($owner);

        [$start, $end] = Volt::test('reports.orders')
            ->set('range', 'custom')
            ->set('customStart', '2026-01-05')
            ->set('customEnd', '2026-01-05')
            ->instance()
            ->dateRange();

        $this->assertSame('2026-01-05 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-05 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    /**
     * An end date before the start date is clamped to a single-day
     * range (the start date) rather than producing a backwards or
     * rejected query - the documented "safely handle" behaviour.
     */
    public function test_an_end_date_before_the_start_date_is_safely_clamped(): void
    {
        $owner = $this->createOwner(Restaurant::factory()->create());
        $this->actingAs($owner);

        [$start, $end] = Volt::test('reports.orders')
            ->set('range', 'custom')
            ->set('customStart', '2026-01-10')
            ->set('customEnd', '2026-01-01')
            ->instance()
            ->dateRange();

        $this->assertSame('2026-01-10 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-10 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function test_orders_outside_the_selected_range_are_excluded(): void
    {
        $restaurant = Restaurant::factory()->create();
        $this->createOrder($restaurant, OrderStatus::Completed, '10.00', Carbon::parse('2026-01-01'));
        $this->createOrder($restaurant, OrderStatus::Completed, '20.00', Carbon::parse('2026-02-01'));

        $report = app(GetOrderReport::class)->handle(
            $restaurant,
            Carbon::parse('2026-01-01')->startOfDay(),
            Carbon::parse('2026-01-31')->endOfDay(),
        );

        $this->assertSame(1, $report['summary']['total_orders']);
    }

    // --- C. Summary metrics ----------------------------------------------------

    public function test_summary_metrics_are_calculated_correctly(): void
    {
        $restaurant = Restaurant::factory()->create();
        $start = Carbon::parse('2026-03-01')->startOfDay();
        $end = Carbon::parse('2026-03-07')->endOfDay();
        $mid = Carbon::parse('2026-03-03');

        $this->createOrder($restaurant, OrderStatus::Completed, '30.00', $mid);
        $this->createOrder($restaurant, OrderStatus::Pending, '20.00', $mid);
        $this->createOrder($restaurant, OrderStatus::Cancelled, '999.00', $mid);

        $summary = app(GetOrderReport::class)->handle($restaurant, $start, $end)['summary'];

        $this->assertSame(3, $summary['total_orders']);
        $this->assertSame(1, $summary['completed_orders']);
        $this->assertSame(1, $summary['cancelled_orders']);
        // Cancelled (999.00) excluded: revenue = 30 + 20 = 50.00
        $this->assertSame('50.00', $summary['revenue']);
        // Average over the 2 non-cancelled orders: 50 / 2 = 25.00
        $this->assertSame('25.00', $summary['average_order_value']);
    }

    public function test_a_zero_data_period_produces_safe_zero_values_with_no_division_by_zero(): void
    {
        $restaurant = Restaurant::factory()->create();

        $summary = app(GetOrderReport::class)->handle(
            $restaurant,
            Carbon::parse('2026-01-01')->startOfDay(),
            Carbon::parse('2026-01-31')->endOfDay(),
        )['summary'];

        $this->assertSame(0, $summary['total_orders']);
        $this->assertSame('0.00', $summary['revenue']);
        $this->assertSame('0.00', $summary['average_order_value']);
    }

    // --- D. Status breakdown -----------------------------------------------

    public function test_status_breakdown_counts_are_correct_and_every_status_is_represented(): void
    {
        $restaurant = Restaurant::factory()->create();
        $start = Carbon::parse('2026-04-01')->startOfDay();
        $end = Carbon::parse('2026-04-30')->endOfDay();
        $inRange = Carbon::parse('2026-04-15');

        $this->createOrder($restaurant, OrderStatus::Pending, '10.00', $inRange);
        $this->createOrder($restaurant, OrderStatus::Confirmed, '10.00', $inRange);
        $this->createOrder($restaurant, OrderStatus::Completed, '10.00', $inRange);
        $this->createOrder($restaurant, OrderStatus::Completed, '10.00', $inRange);
        // Outside the range - must not be counted.
        $this->createOrder($restaurant, OrderStatus::Completed, '10.00', Carbon::parse('2026-05-01'));

        $breakdown = app(GetOrderReport::class)->handle($restaurant, $start, $end)['status_breakdown'];
        $byStatus = collect($breakdown)->keyBy(fn ($row) => $row['status']->value);

        $this->assertCount(6, $breakdown);
        $this->assertSame(1, $byStatus['pending']['count']);
        $this->assertSame(1, $byStatus['confirmed']['count']);
        $this->assertSame(2, $byStatus['completed']['count']);
        $this->assertSame(0, $byStatus['preparing']['count']);
        $this->assertSame(0, $byStatus['ready']['count']);
        $this->assertSame(0, $byStatus['cancelled']['count']);
        $this->assertSame(50.0, $byStatus['completed']['percentage']);
    }

    // --- E. Revenue over time ------------------------------------------------

    public function test_revenue_over_time_daily_totals_are_correct_and_exclude_cancelled(): void
    {
        $restaurant = Restaurant::factory()->create();
        $start = Carbon::parse('2026-05-01')->startOfDay();
        $end = Carbon::parse('2026-05-03')->endOfDay();

        $this->createOrder($restaurant, OrderStatus::Completed, '10.00', Carbon::parse('2026-05-01 09:00'));
        $this->createOrder($restaurant, OrderStatus::Completed, '5.00', Carbon::parse('2026-05-01 18:00'));
        $this->createOrder($restaurant, OrderStatus::Cancelled, '999.00', Carbon::parse('2026-05-02'));
        // 2026-05-03 has no orders at all.

        $points = app(GetOrderReport::class)->handle($restaurant, $start, $end)['revenue_over_time'];
        $byLabel = collect($points)->keyBy('label');

        $this->assertCount(3, $points);
        $this->assertSame('15.00', $byLabel['2026-05-01']['revenue']);
        $this->assertSame('0.00', $byLabel['2026-05-02']['revenue']);
        $this->assertSame('0.00', $byLabel['2026-05-03']['revenue']);
    }

    public function test_revenue_over_time_is_safe_and_empty_for_a_period_with_no_orders(): void
    {
        $restaurant = Restaurant::factory()->create();

        $points = app(GetOrderReport::class)->handle(
            $restaurant,
            Carbon::parse('2026-01-01')->startOfDay(),
            Carbon::parse('2026-01-03')->endOfDay(),
        )['revenue_over_time'];

        $this->assertCount(3, $points);
        foreach ($points as $point) {
            $this->assertSame('0.00', $point['revenue']);
        }
    }

    public function test_revenue_over_time_uses_weekly_buckets_for_a_long_range(): void
    {
        $restaurant = Restaurant::factory()->create();

        $points = app(GetOrderReport::class)->handle(
            $restaurant,
            Carbon::parse('2026-01-01')->startOfDay(),
            Carbon::parse('2026-06-01')->endOfDay(),
        )['revenue_over_time'];

        // A ~5 month range must not produce one point per day.
        $this->assertLessThan(30, count($points));
    }

    // --- F. Top products -------------------------------------------------------

    public function test_top_products_aggregate_quantities_correctly_across_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $start = Carbon::parse('2026-06-01')->startOfDay();
        $end = Carbon::parse('2026-06-30')->endOfDay();
        $inRange = Carbon::parse('2026-06-10');

        $orderA = $this->createOrder($restaurant, OrderStatus::Completed, '10.00', $inRange);
        $orderB = $this->createOrder($restaurant, OrderStatus::Completed, '10.00', $inRange);

        OrderItem::factory()->create([
            'restaurant_id' => $restaurant->id, 'order_id' => $orderA->id,
            'product_name' => 'Burger', 'quantity' => 2, 'unit_price' => '5.00', 'line_total' => '10.00',
        ]);
        OrderItem::factory()->create([
            'restaurant_id' => $restaurant->id, 'order_id' => $orderB->id,
            'product_name' => 'Burger', 'quantity' => 3, 'unit_price' => '5.00', 'line_total' => '15.00',
        ]);
        OrderItem::factory()->create([
            'restaurant_id' => $restaurant->id, 'order_id' => $orderB->id,
            'product_name' => 'Fries', 'quantity' => 1, 'unit_price' => '2.00', 'line_total' => '2.00',
        ]);

        $topProducts = app(GetOrderReport::class)->handle($restaurant, $start, $end)['top_products'];
        $burger = $topProducts->firstWhere('product_name', 'Burger');

        $this->assertSame(5, (int) $burger->total_quantity);
        $this->assertSame(2, (int) $burger->order_count);
        $this->assertSame('25.00', number_format((float) $burger->total_revenue, 2, '.', ''));
        $this->assertSame('Burger', $topProducts->first()->product_name);
    }

    public function test_top_products_exclude_items_from_cancelled_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $start = Carbon::parse('2026-06-01')->startOfDay();
        $end = Carbon::parse('2026-06-30')->endOfDay();
        $order = $this->createOrder($restaurant, OrderStatus::Cancelled, '10.00', Carbon::parse('2026-06-10'));

        OrderItem::factory()->create([
            'restaurant_id' => $restaurant->id, 'order_id' => $order->id,
            'product_name' => 'Pizza', 'quantity' => 4,
        ]);

        $topProducts = app(GetOrderReport::class)->handle($restaurant, $start, $end)['top_products'];

        $this->assertNull($topProducts->firstWhere('product_name', 'Pizza'));
    }

    /**
     * A product renamed after being ordered must not change what a
     * historical report shows - top products is grouped by the order
     * item's own snapshot, never the live product row.
     */
    public function test_top_products_reflect_the_historical_snapshot_not_the_current_product_name(): void
    {
        $restaurant = Restaurant::factory()->create();
        $start = Carbon::parse('2026-06-01')->startOfDay();
        $end = Carbon::parse('2026-06-30')->endOfDay();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id, 'name' => 'Classic Burger']);
        $order = $this->createOrder($restaurant, OrderStatus::Completed, '10.00', Carbon::parse('2026-06-10'));

        OrderItem::factory()->create([
            'restaurant_id' => $restaurant->id, 'order_id' => $order->id, 'product_id' => $product->id,
            'product_name' => 'Classic Burger', 'quantity' => 1,
        ]);

        $product->update(['name' => 'Deluxe Burger']);

        $topProducts = app(GetOrderReport::class)->handle($restaurant, $start, $end)['top_products'];

        $this->assertNotNull($topProducts->firstWhere('product_name', 'Classic Burger'));
        $this->assertNull($topProducts->firstWhere('product_name', 'Deluxe Burger'));
    }

    public function test_top_products_are_limited_to_ten(): void
    {
        $restaurant = Restaurant::factory()->create();
        $order = $this->createOrder($restaurant, OrderStatus::Completed, '10.00', Carbon::parse('2026-06-10'));

        for ($i = 1; $i <= 12; $i++) {
            OrderItem::factory()->create([
                'restaurant_id' => $restaurant->id, 'order_id' => $order->id,
                'product_name' => "Product {$i}", 'quantity' => $i,
            ]);
        }

        $topProducts = app(GetOrderReport::class)->handle(
            $restaurant,
            Carbon::parse('2026-06-01')->startOfDay(),
            Carbon::parse('2026-06-30')->endOfDay(),
        )['top_products'];

        $this->assertCount(10, $topProducts);
        // Highest quantity (12) must be first, proving the limit keeps
        // the top items rather than an arbitrary slice.
        $this->assertSame('Product 12', $topProducts->first()->product_name);
    }

    // --- G. Customer analytics --------------------------------------------------

    public function test_new_customers_are_filtered_to_the_selected_period(): void
    {
        $restaurant = Restaurant::factory()->create();
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'created_at' => '2026-07-10']);
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'created_at' => '2026-08-01']);

        $report = app(GetOrderReport::class)->handle(
            $restaurant,
            Carbon::parse('2026-07-01')->startOfDay(),
            Carbon::parse('2026-07-31')->endOfDay(),
        );

        $this->assertSame(1, $report['new_customers']);
    }

    public function test_top_customers_are_ranked_by_total_spend_and_exclude_cancelled_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $start = Carbon::parse('2026-07-01')->startOfDay();
        $end = Carbon::parse('2026-07-31')->endOfDay();
        $inRange = Carbon::parse('2026-07-15');

        $bigSpender = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Big Spender']);
        $smallSpender = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Small Spender']);

        $this->createOrder($restaurant, OrderStatus::Completed, '100.00', $inRange, $bigSpender);
        $this->createOrder($restaurant, OrderStatus::Cancelled, '999.00', $inRange, $bigSpender);
        $this->createOrder($restaurant, OrderStatus::Completed, '10.00', $inRange, $smallSpender);

        $topCustomers = app(GetOrderReport::class)->handle($restaurant, $start, $end)['top_customers'];

        $this->assertSame('Big Spender', $topCustomers->first()->name);
        $this->assertSame(1, (int) $topCustomers->first()->orders_count);
        $this->assertSame('100.00', number_format((float) $topCustomers->first()->orders_sum_total, 2, '.', ''));
    }

    public function test_a_customer_with_only_cancelled_orders_in_range_does_not_appear_in_top_customers(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $this->createOrder($restaurant, OrderStatus::Cancelled, '999.00', Carbon::parse('2026-07-15'), $customer);

        $topCustomers = app(GetOrderReport::class)->handle(
            $restaurant,
            Carbon::parse('2026-07-01')->startOfDay(),
            Carbon::parse('2026-07-31')->endOfDay(),
        )['top_customers'];

        $this->assertCount(0, $topCustomers);
    }

    // --- H. Multi-tenant security ------------------------------------------

    public function test_every_major_metric_is_isolated_between_restaurants(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $start = Carbon::parse('2026-08-01')->startOfDay();
        $end = Carbon::parse('2026-08-31')->endOfDay();
        $inRange = Carbon::parse('2026-08-15');

        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id, 'name' => 'Restaurant B Customer']);
        $orderB = $this->createOrder($restaurantB, OrderStatus::Completed, '500.00', $inRange, $customerB);
        OrderItem::factory()->create([
            'restaurant_id' => $restaurantB->id, 'order_id' => $orderB->id,
            'product_name' => 'Restaurant B Special', 'quantity' => 9,
        ]);
        Customer::factory()->create(['restaurant_id' => $restaurantB->id, 'created_at' => $inRange]);

        // Restaurant A has zero activity in the period.
        $report = app(GetOrderReport::class)->handle($restaurantA, $start, $end);

        $this->assertSame(0, $report['summary']['total_orders']);
        $this->assertSame('0.00', $report['summary']['revenue']);
        $this->assertSame(0, $report['new_customers']);
        $this->assertCount(0, $report['top_products']);
        $this->assertCount(0, $report['top_customers']);
        foreach ($report['status_breakdown'] as $row) {
            $this->assertSame(0, $row['count']);
        }
    }

    public function test_the_reports_page_only_ever_queries_the_authenticated_users_own_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $this->createOrder($restaurantB, OrderStatus::Completed, '999.00');

        $this->actingAs($ownerA);

        Volt::test('reports.orders')->assertDontSee('999.00');
    }

    // --- I. Security -----------------------------------------------------------

    public function test_no_client_controlled_restaurant_id_property_exists_on_the_component(): void
    {
        $owner = $this->createOwner(Restaurant::factory()->create());
        $this->actingAs($owner);

        $publicProperties = array_keys(get_object_vars(Volt::test('reports.orders')->instance()));

        $this->assertNotContains('restaurant_id', $publicProperties);
        $this->assertSame(['range', 'customStart', 'customEnd'], $publicProperties);
    }

    public function test_a_forged_range_value_safely_falls_back_to_today(): void
    {
        $owner = $this->createOwner(Restaurant::factory()->create());
        $this->actingAs($owner);

        $component = Volt::test('reports.orders')->set('range', 'drop-table-orders');

        [$start, $end] = $component->instance()->dateRange();

        $this->assertTrue($start->isToday());
        $this->assertTrue($end->isToday());
        $component->assertOk();
    }

    public function test_forged_garbage_custom_date_values_are_handled_safely_without_a_crash(): void
    {
        $owner = $this->createOwner(Restaurant::factory()->create());
        $this->actingAs($owner);

        $component = Volt::test('reports.orders')
            ->set('range', 'custom')
            ->set('customStart', 'not-a-date; DROP TABLE orders;--')
            ->set('customEnd', 'also-not-a-date');

        $component->assertOk();

        [$start, $end] = $component->instance()->dateRange();
        $this->assertTrue($start->isToday());
    }

    // --- J. Performance --------------------------------------------------------

    /**
     * The full report - summary, status breakdown, revenue-over-time,
     * top products, top customers, new customers - must execute a
     * small, bounded number of queries regardless of how many orders/
     * products/customers exist, since every list is built from
     * database aggregates rather than loading full datasets into PHP.
     */
    public function test_generating_the_full_report_does_not_scale_with_the_number_of_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $inRange = Carbon::parse('2026-09-15');

        for ($i = 0; $i < 15; $i++) {
            $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
            $order = $this->createOrder($restaurant, OrderStatus::Completed, '10.00', $inRange, $customer);
            OrderItem::factory()->create([
                'restaurant_id' => $restaurant->id, 'order_id' => $order->id,
                'product_name' => "Product {$i}", 'quantity' => 1,
            ]);
        }

        DB::enableQueryLog();
        app(GetOrderReport::class)->handle(
            $restaurant,
            Carbon::parse('2026-09-01')->startOfDay(),
            Carbon::parse('2026-09-30')->endOfDay(),
        );
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A fixed, small number of aggregate queries (summary's several
        // counts/sum, one status breakdown, one revenue-over-time, one
        // top products, one top customers, one new customers) - never
        // one per order/product/customer.
        $this->assertLessThanOrEqual(15, $queryCount);
    }
}
