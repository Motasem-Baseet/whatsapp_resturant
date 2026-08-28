<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderStatusUpdated;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Orders\UpdateOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderBroadcastingTest extends TestCase
{
    use RefreshDatabase;

    private function createOrder(OrderStatus $status, ?Restaurant $restaurant = null): Order
    {
        $restaurant ??= Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        return Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'status' => $status,
        ]);
    }

    /**
     * Same reasoning as WhatsApp\BroadcastingTest::useReverbBroadcaster():
     * routes/channels.php only registers patterns on whichever driver was
     * default at boot, so it must be re-required after switching the
     * config to exercise real channel authorization.
     */
    private function useReverbBroadcaster(): void
    {
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
    }

    // --- Channel authorization ------------------------------------------

    public function test_an_owner_can_authorize_the_orders_channel(): void
    {
        $this->useReverbBroadcaster();
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $user->assignRole(Role::findOrCreate('owner'));

        $response = $this->actingAs($user)->post('/broadcasting/auth', [
            'channel_name' => "private-restaurants.{$restaurant->id}.orders",
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk();
    }

    public function test_a_cashier_can_authorize_the_orders_channel(): void
    {
        $this->useReverbBroadcaster();
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $user->assignRole(Role::findOrCreate('cashier'));

        $response = $this->actingAs($user)->post('/broadcasting/auth', [
            'channel_name' => "private-restaurants.{$restaurant->id}.orders",
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk();
    }

    /**
     * Unlike the inbox channel, kitchen legitimately needs order updates
     * too (their own order list), so this channel intentionally admits
     * all three operational roles.
     */
    public function test_a_kitchen_user_can_authorize_the_orders_channel(): void
    {
        $this->useReverbBroadcaster();
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $user->assignRole(Role::findOrCreate('kitchen'));

        $response = $this->actingAs($user)->post('/broadcasting/auth', [
            'channel_name' => "private-restaurants.{$restaurant->id}.orders",
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk();
    }

    public function test_a_user_with_no_role_cannot_authorize_the_orders_channel(): void
    {
        $this->useReverbBroadcaster();
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);

        $response = $this->actingAs($user)->post('/broadcasting/auth', [
            'channel_name' => "private-restaurants.{$restaurant->id}.orders",
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }

    public function test_a_user_from_a_different_restaurant_cannot_authorize_the_orders_channel(): void
    {
        $this->useReverbBroadcaster();
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $userB = User::factory()->create(['restaurant_id' => $restaurantB->id]);
        $userB->assignRole(Role::findOrCreate('owner'));

        $response = $this->actingAs($userB)->post('/broadcasting/auth', [
            'channel_name' => "private-restaurants.{$restaurantA->id}.orders",
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_authorize_the_orders_channel(): void
    {
        $this->useReverbBroadcaster();
        $restaurant = Restaurant::factory()->create();

        $response = $this->post('/broadcasting/auth', [
            'channel_name' => "private-restaurants.{$restaurant->id}.orders",
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }

    // --- Event dispatching -----------------------------------------------

    public function test_a_successful_transition_dispatches_order_status_updated(): void
    {
        Event::fake([OrderStatusUpdated::class]);

        $order = $this->createOrder(OrderStatus::Pending);

        app(UpdateOrderStatus::class)->handle($order, OrderStatus::Confirmed);

        Event::assertDispatched(OrderStatusUpdated::class, fn ($event) => $event->order->id === $order->id);
    }

    public function test_a_failed_transition_does_not_dispatch_order_status_updated(): void
    {
        Event::fake([OrderStatusUpdated::class]);

        $order = $this->createOrder(OrderStatus::Pending);

        try {
            app(UpdateOrderStatus::class)->handle($order, OrderStatus::Ready);
        } catch (\App\Exceptions\InvalidOrderStatusTransitionException) {
            // expected
        }

        Event::assertNotDispatched(OrderStatusUpdated::class);
    }

    public function test_order_status_updated_broadcasts_on_the_correct_private_channel_with_a_minimal_payload(): void
    {
        $order = $this->createOrder(OrderStatus::Confirmed);

        $event = new OrderStatusUpdated($order);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertSame("private-restaurants.{$order->restaurant_id}.orders", $channels[0]->name);
        $this->assertSame('order.status-updated', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame(['id', 'status'], array_keys($payload));
        $this->assertSame($order->id, $payload['id']);
        $this->assertSame($order->status->value, $payload['status']);
    }

    // --- Pages register the Echo listener --------------------------------

    public function test_the_owner_cashier_orders_list_registers_the_echo_listener(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $owner->assignRole(Role::findOrCreate('owner'));

        $this->actingAs($owner);

        Volt::test('orders.index')
            ->assertSee("echo-private:restaurants.{$restaurant->id}.orders,.order.status-updated", false, false);
    }

    public function test_the_kitchen_orders_list_registers_the_echo_listener(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $kitchen->assignRole(Role::findOrCreate('kitchen'));

        $this->actingAs($kitchen);

        Volt::test('kitchen.orders.index')
            ->assertSee("echo-private:restaurants.{$restaurant->id}.orders,.order.status-updated", false, false);
    }
}
