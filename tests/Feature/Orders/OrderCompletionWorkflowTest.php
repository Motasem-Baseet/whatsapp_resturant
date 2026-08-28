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
 * The Ready -> Completed transition (Phase 22). Inspection found the
 * mechanics already fully correct and in place from Phase 19
 * (OrderStatus/UpdateOrderStatus/OrderPolicy) - there is no new
 * completion service here, only the existing orders.show page's UX
 * around the already-existing transition, and coverage confirming it
 * still behaves correctly.
 */
class OrderCompletionWorkflowTest extends TestCase
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

    public function test_owner_can_complete_a_ready_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Completed->value)
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    public function test_cashier_can_complete_a_ready_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($cashier);

        Volt::test('orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Completed->value)
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }

    /**
     * Kitchen has no completion capability in the existing architecture
     * - canTransitionAsKitchen only permits confirmed->preparing and
     * preparing->ready. Verified two ways: the kitchen page never
     * offers a "Mark Completed" button (nextStatus() has no Ready
     * branch), and a direct forged call is independently rejected by
     * the policy the Livewire action re-checks.
     */
    public function test_kitchen_cannot_complete_an_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($kitchen);

        $component = Volt::test('kitchen.orders.show', ['order' => $order]);
        $this->assertNull($component->instance()->nextStatus());

        $component->call('transitionTo', OrderStatus::Completed->value);

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    public function test_an_invalid_completion_state_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Completed->value)
            ->assertHasErrors('status');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public function test_completion_creates_exactly_one_history_record(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Completed->value);

        $this->assertDatabaseCount('order_status_histories', 1);
        $history = OrderStatusHistory::first();
        $this->assertSame(OrderStatus::Ready->value, $history->from_status);
        $this->assertSame(OrderStatus::Completed->value, $history->to_status);
        $this->assertSame($owner->id, $history->changed_by);
    }

    public function test_completion_broadcasts_the_existing_order_status_updated_event(): void
    {
        Event::fake([OrderStatusUpdated::class]);

        $restaurant = Restaurant::factory()->create();
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        app(UpdateOrderStatus::class)->handle($order, OrderStatus::Completed);

        Event::assertDispatched(OrderStatusUpdated::class, fn ($event) => $event->order->id === $order->id
            && $event->order->status === OrderStatus::Completed);
    }

    public function test_completion_clears_attention(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $threshold = config('orders.attention_thresholds.ready');

        $this->travelTo(now()->subMinutes($threshold + 30));
        $order = $this->createOrder($restaurant, OrderStatus::Ready);
        $this->travelBack();

        $this->assertTrue($order->fresh()->requiresAttention());

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Completed, $owner);

        $this->assertFalse($order->fresh()->requiresAttention());
        $this->assertNull($order->fresh()->attentionReason());
    }

    public function test_a_completed_order_cannot_transition_again(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Completed);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $order]);

        // No buttons are ever offered for a terminal order.
        $component->assertDontSee(__('Mark :status', ['status' => OrderStatus::Cancelled->label()]));

        $component->call('transitionTo', OrderStatus::Pending->value);

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public function test_cross_tenant_completion_fails(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $orderB = $this->createOrder($restaurantB, OrderStatus::Ready);

        $this->actingAs($ownerA)->get(route('orders.show', $orderB))->assertNotFound();

        $this->assertSame(OrderStatus::Ready, $orderB->fresh()->status);
    }

    /**
     * Proves transitionTo() re-authorizes on every call rather than
     * trusting mount()'s earlier check - the owner's role is revoked
     * only after the component has already successfully mounted.
     */
    public function test_completion_reauthorizes_at_action_time_not_just_mount(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $order]);

        $owner->removeRole('owner');

        $component->call('transitionTo', OrderStatus::Completed->value);

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    // --- Real-time compatibility ------------------------------------------

    public function test_the_order_list_reflects_completion_after_the_existing_real_time_event(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($owner);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Completed, $owner);

        Volt::test('orders.index')
            ->call('onOrderStatusUpdated')
            ->assertSee(OrderStatus::Completed->label());
    }

    public function test_the_order_detail_page_reflects_completion_via_the_existing_listener(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $order]);

        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Completed, $owner);

        $component->call('onOrderStatusUpdated', ['id' => $order->id, 'status' => OrderStatus::Completed->value]);

        $this->assertSame(OrderStatus::Completed, $component->get('order')->status);
        $component->assertSee(__('This order is complete. No further action is needed.'));
    }
}
