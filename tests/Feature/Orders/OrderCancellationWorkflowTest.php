<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Orders\UpdateOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The explicit cancellation workflow (Phase 22): a confirmation modal
 * around the existing Cancelled transition, plus an optional
 * cancellation_reason column. cancelOrder() is a separate Livewire
 * action from the generic transitionTo() (see orders/show.blade.php),
 * but both funnel through the same UpdateOrderStatus service - there
 * is no second lifecycle or second history mechanism here.
 */
class OrderCancellationWorkflowTest extends TestCase
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

    public function test_owner_can_cancel_a_pending_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->set('cancellationReason', 'Customer changed their mind')
            ->call('cancelOrder')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Customer changed their mind', $order->cancellation_reason);
    }

    public function test_cashier_can_cancel_a_confirmed_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $this->actingAs($cashier);

        Volt::test('orders.show', ['order' => $order])
            ->call('cancelOrder')
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    /**
     * Kitchen's own order page has no cancelOrder() action at all - the
     * only cancellation path it can reach is the shared transitionTo(),
     * which canTransitionAsKitchen already rejects for "cancelled"
     * regardless of the order's current status.
     */
    public function test_kitchen_cannot_cancel_an_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $this->actingAs($kitchen);

        Volt::test('kitchen.orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Cancelled->value);

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_kitchen_cannot_reach_the_owner_cashier_cancellation_action_at_all(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $this->actingAs($kitchen)->get(route('orders.show', $order))->assertForbidden();
    }

    /**
     * Ready orders may only transition to Completed per OrderStatus -
     * ready -> cancelled is not a legal transition, so the cancellation
     * action must reject it exactly like any other invalid transition,
     * not silently allow it because it came through a different action
     * method.
     */
    public function test_an_invalid_cancellation_transition_is_rejected_according_to_order_status(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->set('cancellationReason', 'Attempted cancel of a ready order')
            ->call('cancelOrder')
            ->assertHasErrors('status');

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    public function test_a_completed_order_cannot_be_cancelled(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Completed);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->call('cancelOrder')
            ->assertHasErrors('status');

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_cancellation_results_in_the_correct_terminal_state_with_no_further_transitions_offered(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $order])->call('cancelOrder');

        $this->assertTrue($component->get('order')->status === OrderStatus::Cancelled);
        $this->assertSame([], $component->get('order')->status->allowedTransitions());
    }

    public function test_cancellation_reason_is_saved_when_provided(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->set('cancellationReason', 'Out of stock')
            ->call('cancelOrder');

        $this->assertSame('Out of stock', $order->fresh()->cancellation_reason);
    }

    /**
     * The reason is optional per the final validation rule
     * (nullable|string|max:1000) - omitting it must not block the
     * cancellation itself, and the column is simply left null.
     */
    public function test_cancellation_succeeds_and_reason_is_null_when_omitted(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->call('cancelOrder')
            ->assertHasNoErrors();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Cancelled, $fresh->status);
        $this->assertNull($fresh->cancellation_reason);
    }

    public function test_a_reason_exceeding_the_maximum_length_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->set('cancellationReason', str_repeat('a', 1001))
            ->call('cancelOrder')
            ->assertHasErrors(['cancellationReason' => 'max']);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_a_failed_cancellation_does_not_persist_a_reason(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->set('cancellationReason', 'This should never be saved')
            ->call('cancelOrder');

        $this->assertNull($order->fresh()->cancellation_reason);
    }

    public function test_successful_cancellation_creates_exactly_one_history_row(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Preparing);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])->call('cancelOrder');

        $this->assertDatabaseCount('order_status_histories', 1);
        $history = OrderStatusHistory::first();
        $this->assertSame(OrderStatus::Preparing->value, $history->from_status);
        $this->assertSame(OrderStatus::Cancelled->value, $history->to_status);
        $this->assertSame($owner->id, $history->changed_by);
    }

    public function test_cancellation_broadcasts_the_existing_order_status_updated_event(): void
    {
        Event::fake([OrderStatusUpdated::class]);

        $restaurant = Restaurant::factory()->create();
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        app(UpdateOrderStatus::class)->handle($order, OrderStatus::Cancelled);

        Event::assertDispatched(OrderStatusUpdated::class, fn ($event) => $event->order->id === $order->id
            && $event->order->status === OrderStatus::Cancelled);
    }

    public function test_cancellation_clears_attention(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $threshold = config('orders.attention_thresholds.pending');

        $this->travelTo(now()->subMinutes($threshold + 5));
        $order = $this->createOrder($restaurant, OrderStatus::Pending);
        $this->travelBack();

        $this->assertTrue($order->fresh()->requiresAttention());

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled, $owner);

        $this->assertFalse($order->fresh()->requiresAttention());
    }

    public function test_a_cancelled_order_cannot_transition_again(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Cancelled);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Confirmed->value);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    /**
     * A retried cancellation on an already-cancelled order is itself an
     * invalid transition (cancelled has no allowed transitions), so it
     * is rejected the same way any other invalid transition is - no
     * special-casing was added, and none was needed.
     */
    public function test_retrying_cancellation_does_not_create_a_duplicate_history_row(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $order]);
        $component->set('cancellationReason', 'First attempt')->call('cancelOrder');
        $component->set('cancellationReason', 'Second attempt')->call('cancelOrder')->assertHasErrors('status');

        $this->assertDatabaseCount('order_status_histories', 1);
        $this->assertSame('First attempt', $order->fresh()->cancellation_reason);
    }

    public function test_cross_tenant_cancellation_fails(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $orderB = $this->createOrder($restaurantB, OrderStatus::Pending);

        $this->actingAs($ownerA)->get(route('orders.show', $orderB))->assertNotFound();

        $this->assertSame(OrderStatus::Pending, $orderB->fresh()->status);
    }

    public function test_cancellation_reauthorizes_at_action_time_not_just_mount(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $order]);

        $owner->removeRole('owner');

        $component->call('cancelOrder');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    // --- Security ------------------------------------------------------------

    public function test_no_restaurant_id_or_order_id_property_is_publicly_writable(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('orders.show', ['order' => $order])->instance())
        );

        $this->assertNotContains('restaurant_id', $publicProperties);
        $this->assertNotContains('order_id', $publicProperties);
        $this->assertSame(['order', 'cancellationReason'], $publicProperties);
    }

    public function test_forged_status_values_cannot_be_smuggled_through_the_generic_transition_action(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $this->expectException(\ValueError::class);

        Volt::test('orders.show', ['order' => $order])
            ->call('transitionTo', 'not-a-real-status');
    }

    // --- Real-time compatibility ------------------------------------------

    public function test_the_order_list_reflects_cancellation_after_the_existing_real_time_event(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled, $owner);

        Volt::test('orders.index')
            ->call('onOrderStatusUpdated')
            ->assertSee(OrderStatus::Cancelled->label());
    }

    public function test_the_order_detail_page_reflects_cancellation_via_the_existing_listener(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $order]);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled, $owner, 'Cancelled by another session');

        $component->call('onOrderStatusUpdated', ['id' => $order->id, 'status' => OrderStatus::Cancelled->value]);

        $this->assertSame(OrderStatus::Cancelled, $component->get('order')->status);
        $component->assertSee(__('Reason: :reason', ['reason' => 'Cancelled by another session']));
    }

    /**
     * A non-matching event must not cause this page to pick up another
     * order's cancellation - mirrors Phase 20's own isolation guarantee,
     * verified again here specifically for a terminal transition.
     */
    public function test_a_non_matching_event_does_not_leak_another_orders_cancellation_onto_this_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $openOrder = $this->createOrder($restaurant, OrderStatus::Pending);
        $otherOrder = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $openOrder]);

        app(UpdateOrderStatus::class)->handle($otherOrder->fresh(), OrderStatus::Cancelled, $owner);

        $component->call('onOrderStatusUpdated', ['id' => $otherOrder->id, 'status' => OrderStatus::Cancelled->value]);

        $this->assertSame(OrderStatus::Pending, $component->get('order')->status);
    }

    /**
     * Duplicate delivery of the same terminal event must not cause any
     * extra history/state changes - the listener only ever reads.
     */
    public function test_duplicate_cancellation_events_cause_no_duplicate_history(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Cancelled, $owner);

        $component = Volt::test('orders.show', ['order' => $order]);
        $event = ['id' => $order->id, 'status' => OrderStatus::Cancelled->value];

        $component->call('onOrderStatusUpdated', $event);
        $component->call('onOrderStatusUpdated', $event);

        $this->assertDatabaseCount('order_status_histories', 1);
    }
}
