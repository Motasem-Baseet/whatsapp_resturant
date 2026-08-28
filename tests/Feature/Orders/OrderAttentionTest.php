<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Dashboard\GetDashboardMetrics;
use App\Services\Orders\UpdateOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Order operational attention (Phase 21) - a purely derived, computed
 * indicator built on top of the existing OrderStatus/UpdateOrderStatus/
 * OrderStatusHistory architecture from Phases 19-20. Nothing here is
 * persisted; every assertion exercises Order::requiresAttention() and
 * friends (or a page that renders them) directly. Time-dependent
 * behaviour uses travelTo()/travelBack() rather than real sleeps.
 */
class OrderAttentionTest extends TestCase
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

    private function createOrder(Restaurant $restaurant, OrderStatus $status): Order
    {
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        return Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'status' => $status,
        ]);
    }

    // --- A. Attention classification --------------------------------------

    public function test_a_fresh_pending_order_does_not_require_attention(): void
    {
        $restaurant = Restaurant::factory()->create();
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->assertFalse($order->requiresAttention());
        $this->assertNull($order->attentionReason());
    }

    public function test_an_old_pending_order_requires_attention(): void
    {
        $restaurant = Restaurant::factory()->create();
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold + 5));
        $order = $this->createOrder($restaurant, OrderStatus::Pending);
        $this->travelBack();

        $this->assertTrue($order->requiresAttention());
        $this->assertSame('pending_too_long', $order->attentionReason());
    }

    public function test_a_completed_order_never_requires_attention_regardless_of_age(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->travelTo(now()->subDays(30));
        $order = $this->createOrder($restaurant, OrderStatus::Completed);
        $this->travelBack();

        $this->assertFalse($order->requiresAttention());
        $this->assertNull($order->attentionReason());
        $this->assertNull($order->attentionThresholdMinutes());
    }

    public function test_a_cancelled_order_never_requires_attention_regardless_of_age(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->travelTo(now()->subDays(30));
        $order = $this->createOrder($restaurant, OrderStatus::Cancelled);
        $this->travelBack();

        $this->assertFalse($order->requiresAttention());
        $this->assertNull($order->attentionReason());
    }

    // --- B. currentStatusStartedAt() ---------------------------------------

    public function test_current_status_started_at_falls_back_to_created_at_with_no_transition_history(): void
    {
        $restaurant = Restaurant::factory()->create();
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->assertTrue($order->created_at->equalTo($order->currentStatusStartedAt()));
    }

    public function test_current_status_started_at_uses_the_latest_transition_into_the_current_status(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->travelTo(now()->addMinutes(5));
        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Confirmed, $owner);
        $confirmedAt = now();

        $this->travelTo(now()->addMinutes(5));
        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Preparing, $owner);
        $preparingAt = now();
        $this->travelBack();

        $fresh = $order->fresh();

        // Compared at second precision - the datetime column storing
        // created_at doesn't retain the microseconds an in-memory now()
        // carries, so an exact Carbon equalTo() would spuriously fail.
        //
        // Must reflect the transition INTO the order's current status
        // (preparing), not an older entry (confirmed) or the original
        // created_at.
        $this->assertSame($preparingAt->timestamp, $fresh->currentStatusStartedAt()->timestamp);
        $this->assertNotSame($confirmedAt->timestamp, $fresh->currentStatusStartedAt()->timestamp);
    }

    // --- C. Threshold boundary ----------------------------------------------

    public function test_an_order_under_its_threshold_does_not_require_attention(): void
    {
        $restaurant = Restaurant::factory()->create();
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold - 1));
        $order = $this->createOrder($restaurant, OrderStatus::Pending);
        $this->travelBack();

        $this->assertFalse($order->requiresAttention());
    }

    /**
     * The documented rule is ">=" - an order exactly at its threshold
     * already requires attention, not only once it exceeds it.
     */
    public function test_an_order_exactly_at_its_threshold_requires_attention(): void
    {
        $restaurant = Restaurant::factory()->create();
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold));
        $order = $this->createOrder($restaurant, OrderStatus::Pending);
        $this->travelBack();

        $this->assertTrue($order->requiresAttention());
    }

    public function test_an_order_over_its_threshold_requires_attention(): void
    {
        $restaurant = Restaurant::factory()->create();
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold + 30));
        $order = $this->createOrder($restaurant, OrderStatus::Pending);
        $this->travelBack();

        $this->assertTrue($order->requiresAttention());
    }

    // --- D. Owner/cashier list UX -------------------------------------------

    public function test_the_order_list_shows_a_needs_attention_badge_for_a_qualifying_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold + 5));
        $this->createOrder($restaurant, OrderStatus::Pending);
        $this->travelBack();

        $this->actingAs($owner);

        Volt::test('orders.index')->assertSee(__('Needs attention'));
    }

    public function test_the_order_list_attention_filter_shows_only_attention_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold + 5));
        $overdue = $this->createOrder($restaurant, OrderStatus::Pending);
        $this->travelBack();
        $fresh = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $ids = Volt::test('orders.index')
            ->set('attentionOnly', true)
            ->instance()
            ->orders()
            ->pluck('id')
            ->all();

        $this->assertSame([$overdue->id], $ids);
        $this->assertNotContains($fresh->id, $ids);
    }

    public function test_a_forged_non_boolean_attention_filter_value_is_handled_safely(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        // Livewire coerces a set() value against the property's native
        // `bool` type declaration - there is no code path where this
        // property can hold anything other than true/false, so a forged
        // non-boolean value can only ever collapse to one of those two,
        // never reach a query unsafely.
        Volt::test('orders.index')
            ->set('attentionOnly', 'not-a-boolean')
            ->assertOk();
    }

    public function test_completed_and_cancelled_orders_never_appear_as_needing_attention_in_the_list(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->travelTo(now()->subDays(30));
        $completed = $this->createOrder($restaurant, OrderStatus::Completed);
        $cancelled = $this->createOrder($restaurant, OrderStatus::Cancelled);
        $this->travelBack();

        $this->actingAs($owner);

        $ids = Volt::test('orders.index')
            ->set('attentionOnly', true)
            ->instance()
            ->orders()
            ->pluck('id')
            ->all();

        $this->assertNotContains($completed->id, $ids);
        $this->assertNotContains($cancelled->id, $ids);
    }

    public function test_the_order_list_attention_filter_is_tenant_scoped(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold + 5));
        $overdueB = $this->createOrder($restaurantB, OrderStatus::Pending);
        $this->travelBack();

        $this->actingAs($ownerA);

        $ids = Volt::test('orders.index')
            ->set('attentionOnly', true)
            ->instance()
            ->orders()
            ->pluck('id')
            ->all();

        $this->assertNotContains($overdueB->id, $ids);
    }

    // --- E. Kitchen list UX --------------------------------------------------

    public function test_the_kitchen_list_shows_attention_for_an_overdue_preparing_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $threshold = config('orders.attention_thresholds.preparing');

        $this->travelTo(now()->subMinutes($threshold + 5));
        $this->createOrder($restaurant, OrderStatus::Preparing);
        $this->travelBack();

        $this->actingAs($kitchen);

        Volt::test('kitchen.orders.index')->assertSee(__('Needs attention'));
    }

    /**
     * Ready orders are outside kitchen's own workflow responsibility
     * (only owner/cashier can complete one) - even an overdue ready
     * order must not be flagged as needing kitchen attention.
     */
    public function test_the_kitchen_list_never_flags_an_overdue_ready_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $threshold = config('orders.attention_thresholds.ready');

        $this->travelTo(now()->subMinutes($threshold + 30));
        $this->createOrder($restaurant, OrderStatus::Ready);
        $this->travelBack();

        $this->actingAs($kitchen);

        Volt::test('kitchen.orders.index')->assertDontSee(__('Needs attention'));
    }

    public function test_kitchen_forbidden_transitions_remain_forbidden_regardless_of_attention_state(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $threshold = config('orders.attention_thresholds.preparing');

        $this->travelTo(now()->subMinutes($threshold + 30));
        $order = $this->createOrder($restaurant, OrderStatus::Preparing);
        $this->travelBack();

        $this->actingAs($kitchen);

        Volt::test('kitchen.orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Cancelled->value);

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
    }

    // --- F. Dashboard integration --------------------------------------------

    public function test_dashboard_attention_count_is_tenant_scoped_for_owner_cashier(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold + 5));
        $this->createOrder($restaurantA, OrderStatus::Pending);
        $this->createOrder($restaurantB, OrderStatus::Pending);
        $this->createOrder($restaurantB, OrderStatus::Pending);
        $this->travelBack();

        $metrics = app(GetDashboardMetrics::class)->forOwnerOrCashier($restaurantA, $ownerA);

        $this->assertSame(1, $metrics['attention_orders_count']);
    }

    public function test_dashboard_attention_count_excludes_completed_and_cancelled_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->travelTo(now()->subDays(30));
        $this->createOrder($restaurant, OrderStatus::Completed);
        $this->createOrder($restaurant, OrderStatus::Cancelled);
        $this->travelBack();

        $metrics = app(GetDashboardMetrics::class)->forOwnerOrCashier($restaurant, $owner);

        $this->assertSame(0, $metrics['attention_orders_count']);
    }

    public function test_kitchen_dashboard_attention_count_excludes_ready_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $threshold = config('orders.attention_thresholds.ready');

        $this->travelTo(now()->subMinutes($threshold + 30));
        $this->createOrder($restaurant, OrderStatus::Ready);
        $this->travelBack();

        $metrics = app(GetDashboardMetrics::class)->forKitchen($restaurant);

        $this->assertSame(0, $metrics['attention_orders_count']);
    }

    public function test_kitchen_dashboard_attention_count_includes_overdue_preparing_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $threshold = config('orders.attention_thresholds.preparing');

        $this->travelTo(now()->subMinutes($threshold + 5));
        $this->createOrder($restaurant, OrderStatus::Preparing);
        $this->travelBack();

        $metrics = app(GetDashboardMetrics::class)->forKitchen($restaurant);

        $this->assertSame(1, $metrics['attention_orders_count']);
    }

    public function test_kitchen_dashboard_still_contains_no_revenue_or_customer_data_alongside_the_new_metric(): void
    {
        $restaurant = Restaurant::factory()->create();

        $metrics = app(GetDashboardMetrics::class)->forKitchen($restaurant);

        $this->assertArrayHasKey('attention_orders_count', $metrics);
        $this->assertArrayNotHasKey('todays_revenue', $metrics);
        $this->assertArrayNotHasKey('todays_new_customers', $metrics);
        $this->assertArrayNotHasKey('unread_conversations', $metrics);
    }

    // --- G. Real-time compatibility ------------------------------------------

    /**
     * Attention has no event/channel of its own (Phase 21 introduces
     * none) - it recomputes naturally whenever the existing order data
     * is re-read, which is exactly what happens after a real transition
     * via the already-existing UpdateOrderStatus/OrderStatusUpdated
     * pipeline from Phases 19-20.
     */
    public function test_an_overdue_pending_order_no_longer_needs_attention_once_confirmed(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold + 5));
        $order = $this->createOrder($restaurant, OrderStatus::Pending);
        $this->travelBack();

        $this->assertTrue($order->fresh()->requiresAttention());

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Confirmed, $owner);

        // Just transitioned - well under the (separate) "preparing"
        // threshold that now governs it.
        $this->assertFalse($order->fresh()->requiresAttention());
    }

    public function test_the_owner_cashier_list_reflects_the_new_attention_state_after_a_transition(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold + 5));
        $order = $this->createOrder($restaurant, OrderStatus::Pending);
        $this->travelBack();

        $this->actingAs($owner);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Confirmed, $owner);

        Volt::test('orders.index')
            ->set('attentionOnly', true)
            ->assertDontSee('#'.$order->id);
    }
}
