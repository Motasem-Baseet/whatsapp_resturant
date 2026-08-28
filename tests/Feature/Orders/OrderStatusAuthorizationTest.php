<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The full role authorization matrix for the owner/cashier order
 * detail page's transitionTo() action - OrderStatusTransitionTest
 * already exhaustively covers every valid/invalid status combination
 * at the UpdateOrderStatus service level, and OrderManagementTest
 * already covers route-level access to orders.index/orders.create and
 * the cross-tenant 404 on orders.show itself. What neither covers is
 * whether a non-owner/cashier session can reach the transitionTo()
 * action at all, mirroring the same rigor
 * Kitchen\KitchenOrderWorkflowTest already applies to the kitchen
 * equivalent.
 */
class OrderStatusAuthorizationTest extends TestCase
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

    public function test_owner_can_transition_an_order_via_the_order_detail_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Confirmed->value)
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_cashier_can_transition_an_order_via_the_order_detail_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($cashier);

        Volt::test('orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Cancelled->value)
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    /**
     * Kitchen has its own separate viewAsKitchen/transitionTo on the
     * kitchen order page - it has no access to the owner/cashier
     * orders.show route or its transitionTo() action at all. mount()
     * denies access before the action is ever reachable.
     */
    public function test_kitchen_cannot_mount_or_transition_via_the_owner_cashier_order_detail_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($kitchen)->get(route('orders.show', $order))->assertForbidden();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_a_roleless_user_cannot_mount_or_transition_via_the_order_detail_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $roleless = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($roleless)->get(route('orders.show', $order))->assertForbidden();

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_an_unauthenticated_request_is_redirected_away_from_the_order_detail_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->get(route('orders.show', $order))->assertRedirect(route('login'));

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    /**
     * A forged status string that doesn't correspond to any OrderStatus
     * case must fail validation rather than reaching UpdateOrderStatus
     * at all (which only accepts a real backed enum value).
     */
    public function test_a_forged_unknown_status_string_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner);

        $this->expectException(\ValueError::class);

        Volt::test('orders.show', ['order' => $order])
            ->call('transitionTo', 'not-a-real-status');
    }

    /**
     * Even though this session legitimately owns order A, a forged call
     * naming order B's id must not let it act on another restaurant's
     * order - the mount()-time authorize('view', ...) on the real
     * order actually loaded by the component already prevents this,
     * since the Livewire component is bound to the specific $order
     * model resolved from the route, not a client-suppliable id.
     */
    public function test_a_cross_tenant_order_cannot_be_mounted_or_transitioned(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $orderB = $this->createOrder($restaurantB, OrderStatus::Pending);

        $this->actingAs($ownerA)->get(route('orders.show', $orderB))->assertNotFound();

        $this->assertSame(OrderStatus::Pending, $orderB->fresh()->status);
    }
}
