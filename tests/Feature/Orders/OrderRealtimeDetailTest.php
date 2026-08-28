<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Orders\UpdateOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Real-time updates on the order detail pages (Phase 20), built
 * directly on Phase 19's existing OrderStatusUpdated event and
 * restaurants.{id}.orders channel - no new event, channel, or service
 * was introduced. These tests exercise the Livewire listener methods
 * directly (matching RealtimeInboxTest's own approach for the
 * conversation detail page), since the event only needs to prove it
 * triggers a correct database re-query, not that Reverb itself
 * delivers messages.
 */
class OrderRealtimeDetailTest extends TestCase
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

    // --- A. Listener registration ----------------------------------------

    public function test_the_owner_cashier_order_detail_page_registers_the_echo_listener_for_its_own_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->assertSee("echo-private:restaurants.{$restaurant->id}.orders,.order.status-updated", false, false);
    }

    public function test_the_kitchen_order_detail_page_registers_the_echo_listener_for_its_own_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $this->actingAs($kitchen);

        Volt::test('kitchen.orders.show', ['order' => $order])
            ->assertSee("echo-private:restaurants.{$restaurant->id}.orders,.order.status-updated", false, false);
    }

    // --- B/E. Matching event refreshes current status, actions, timeline --

    public function test_a_matching_event_on_the_owner_cashier_page_refreshes_status_and_timeline(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $order]);

        // Simulate another authorized session transitioning the same
        // order, entirely independently of this component instance.
        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Confirmed, $owner);

        $component->call('onOrderStatusUpdated', ['id' => $order->id, 'status' => OrderStatus::Confirmed->value]);

        $this->assertSame(OrderStatus::Confirmed, $component->get('order')->status);
        $this->assertCount(1, $component->instance()->statusHistory());
        // The newly-current status's own next actions (Preparing/Cancelled)
        // are re-derived from the refreshed order, not the stale one.
        $component->assertSee(OrderStatus::Preparing->label());
    }

    public function test_a_matching_event_on_the_kitchen_page_refreshes_status_and_next_action(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $this->actingAs($kitchen);

        $component = Volt::test('kitchen.orders.show', ['order' => $order]);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Preparing, $kitchen);

        $component->call('onOrderStatusUpdated', ['id' => $order->id, 'status' => OrderStatus::Preparing->value]);

        $this->assertSame(OrderStatus::Preparing, $component->get('order')->status);
        $this->assertSame(OrderStatus::Ready, $component->instance()->nextStatus());
    }

    // --- C. Different-order events are ignored ----------------------------

    /**
     * Livewire's model synthesizer lazily re-resolves a typed Eloquent
     * property from the database on first access within any method call
     * (see ModelSynth::hydrate()'s lazy proxy), so $this->order's own
     * attributes are already current by the time the guard clause reads
     * $this->order->id - independently of this listener's own logic.
     * What the guard clause actually controls is whether the *extra*
     * explicit refresh() below it ever runs. This is verified here via
     * query count rather than asserted model state, since asserting on
     * $component->get('order')->status would pass regardless of whether
     * the guard clause existed at all.
     */
    public function test_an_event_for_a_different_order_does_not_trigger_the_extra_refresh_query(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $openOrder = $this->createOrder($restaurant, OrderStatus::Pending);
        $otherOrder = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $mismatchedComponent = Volt::test('orders.show', ['order' => $openOrder]);
        DB::enableQueryLog();
        $mismatchedComponent->call('onOrderStatusUpdated', ['id' => $otherOrder->id, 'status' => OrderStatus::Confirmed->value]);
        $mismatchedQueryCount = count(DB::getQueryLog());
        DB::flushQueryLog();

        $matchedComponent = Volt::test('orders.show', ['order' => $openOrder]);
        $matchedComponent->call('onOrderStatusUpdated', ['id' => $openOrder->id, 'status' => OrderStatus::Confirmed->value]);
        $matchedQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan($matchedQueryCount, $mismatchedQueryCount);
    }

    public function test_an_event_for_a_different_order_leaves_that_other_orders_own_data_untouched(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $openOrder = $this->createOrder($restaurant, OrderStatus::Pending);
        $otherOrder = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $openOrder])
            ->call('onOrderStatusUpdated', ['id' => $otherOrder->id, 'status' => OrderStatus::Confirmed->value]);

        // The listener never writes anything - it only ever reads - so an
        // event naming another order can't have side-effected it either.
        $this->assertSame(OrderStatus::Pending, $otherOrder->fresh()->status);
    }

    // --- F. History correctness -------------------------------------------

    public function test_the_timeline_is_ordered_chronologically_oldest_first(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Confirmed, $owner);
        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Preparing, $owner);
        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Ready, $owner);

        $history = Volt::test('orders.show', ['order' => $order->fresh()])
            ->instance()
            ->statusHistory();

        $this->assertSame([
            OrderStatus::Confirmed->value,
            OrderStatus::Preparing->value,
            OrderStatus::Ready->value,
        ], $history->pluck('to_status')->all());
    }

    public function test_the_page_shows_the_orders_original_created_state_without_a_fake_history_row(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $order]);

        $component->assertSee(__('Order created'));
        $component->assertSee($order->created_at->format('M j, Y g:i A'));

        // No transition has happened yet, so the real audit table must
        // still be empty - the "created" line above is presentation only.
        $this->assertCount(0, $component->instance()->statusHistory());
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    // --- G. Role behavior: real-time never grants new capability ----------

    public function test_kitchen_still_cannot_perform_a_forbidden_transition_after_a_live_refresh(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $this->actingAs($kitchen);

        $component = Volt::test('kitchen.orders.show', ['order' => $order]);
        $component->call('onOrderStatusUpdated', ['id' => $order->id, 'status' => OrderStatus::Confirmed->value]);

        // Kitchen may never set "cancelled", regardless of any live
        // refresh having just occurred - canTransitionAsKitchen still
        // rejects it, and the domain transition is never reached.
        $component->call('transitionTo', OrderStatus::Cancelled->value);

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    // --- H. Duplicate event safety -----------------------------------------

    public function test_calling_the_listener_twice_with_the_same_event_does_not_duplicate_anything(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Confirmed, $owner);

        $component = Volt::test('orders.show', ['order' => $order]);
        $event = ['id' => $order->id, 'status' => OrderStatus::Confirmed->value];

        $component->call('onOrderStatusUpdated', $event);
        $component->call('onOrderStatusUpdated', $event);
        $component->call('onOrderStatusUpdated', $event);

        $this->assertSame(OrderStatus::Confirmed, $component->get('order')->status);
        $this->assertCount(1, $component->instance()->statusHistory());
        $this->assertDatabaseCount('order_status_histories', 1);
    }
}
