<?php

namespace Tests\Feature\Kitchen;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KitchenOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(?Restaurant $restaurant = null): User
    {
        $restaurant ??= Restaurant::factory()->create();

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

    // --- Route access (checklist 1-3) ------------------------------------

    public function test_kitchen_user_can_access_kitchen_orders_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen)->get(route('kitchen.orders.index'))->assertOk();
    }

    public function test_owner_cannot_access_the_kitchen_only_route(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)->get(route('kitchen.orders.index'))->assertForbidden();
    }

    public function test_cashier_cannot_access_the_kitchen_only_route(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier)->get(route('kitchen.orders.index'))->assertForbidden();
    }

    // --- Tenant isolation on the list (checklist 4-5) --------------------

    public function test_kitchen_can_see_orders_from_its_own_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $response = $this->actingAs($kitchen)->get(route('kitchen.orders.index'));

        $response->assertOk();
        $response->assertSee('#'.$order->id);
    }

    public function test_kitchen_cannot_see_orders_from_another_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $kitchenA = $this->createEmployee($restaurantA, 'kitchen');
        $orderB = $this->createOrder($restaurantB, OrderStatus::Confirmed);

        $response = $this->actingAs($kitchenA)->get(route('kitchen.orders.index'));

        $response->assertOk();
        $response->assertDontSee('#'.$orderB->id);
    }

    // --- Status filtering on the list (checklist 6-11) -------------------

    public function test_kitchen_list_excludes_pending_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $response = $this->actingAs($kitchen)->get(route('kitchen.orders.index'));

        $response->assertDontSee('#'.$order->id);
    }

    public function test_kitchen_list_excludes_completed_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Completed);

        $response = $this->actingAs($kitchen)->get(route('kitchen.orders.index'));

        $response->assertDontSee('#'.$order->id);
    }

    public function test_kitchen_list_excludes_cancelled_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Cancelled);

        $response = $this->actingAs($kitchen)->get(route('kitchen.orders.index'));

        $response->assertDontSee('#'.$order->id);
    }

    public function test_kitchen_list_includes_confirmed_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $response = $this->actingAs($kitchen)->get(route('kitchen.orders.index'));

        $response->assertSee('#'.$order->id);
    }

    public function test_kitchen_list_includes_preparing_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Preparing);

        $response = $this->actingAs($kitchen)->get(route('kitchen.orders.index'));

        $response->assertSee('#'.$order->id);
    }

    public function test_kitchen_list_includes_ready_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $response = $this->actingAs($kitchen)->get(route('kitchen.orders.index'));

        $response->assertSee('#'.$order->id);
    }

    public function test_kitchen_list_orders_preparing_before_confirmed_before_ready(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $confirmed = $this->createOrder($restaurant, OrderStatus::Confirmed);
        $preparing = $this->createOrder($restaurant, OrderStatus::Preparing);
        $ready = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($kitchen);

        $ids = Volt::test('kitchen.orders.index')->instance()->orders()->pluck('id')->all();

        $this->assertSame([$preparing->id, $confirmed->id, $ready->id], $ids);
    }

    // --- Order detail access (checklist 12-13) ---------------------------

    public function test_kitchen_can_view_a_same_restaurant_kitchen_relevant_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $response = $this->actingAs($kitchen)->get(route('kitchen.orders.show', $order));

        $response->assertOk();
        $response->assertSee($order->customer->name);
    }

    public function test_cross_tenant_kitchen_order_route_returns_404(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $kitchenA = $this->createEmployee($restaurantA, 'kitchen');
        $orderB = $this->createOrder($restaurantB, OrderStatus::Confirmed);

        $response = $this->actingAs($kitchenA)->get(route('kitchen.orders.show', $orderB));

        $response->assertNotFound();
    }

    // --- Allowed transitions (checklist 14-15, 21-22) ---------------------

    public function test_kitchen_can_transition_confirmed_to_preparing(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $this->actingAs($kitchen);

        Volt::test('kitchen.orders.show', ['order' => $order])
            ->call('transitionTo', 'preparing')
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
    }

    public function test_kitchen_can_transition_preparing_to_ready(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Preparing);

        $this->actingAs($kitchen);

        Volt::test('kitchen.orders.show', ['order' => $order])
            ->call('transitionTo', 'ready')
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    public function test_a_valid_kitchen_transition_leaves_totals_untouched(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);
        $order->subtotal = '12.34';
        $order->total = '12.34';
        $order->save();

        $this->actingAs($kitchen);

        Volt::test('kitchen.orders.show', ['order' => $order])
            ->call('transitionTo', 'preparing');

        $this->assertSame('12.34', (string) $order->fresh()->total);
        $this->assertSame('12.34', (string) $order->fresh()->subtotal);
    }

    // --- Disallowed transitions (checklist 16-20) -------------------------
    //
    // Each disallowed transition is verified two ways:
    //  1. Directly against the policy method - the precise, unambiguous
    //     statement of the business rule.
    //  2. Through the actual Livewire component - confirming the
    //     observable outcome (the order's status in the database is
    //     left untouched). Livewire's test harness renders a failed
    //     authorize() call as a genuine 403 HTTP response rather than
    //     re-throwing AuthorizationException to the PHP test caller
    //     (see Livewire\Features\SupportTesting\RequestBroker, which
    //     explicitly excludes AuthorizationException from its
    //     "rethrow" list), so the database state - not a caught
    //     exception - is the reliable thing to assert on here.

    public function test_kitchen_cannot_transition_pending_to_confirmed(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->assertFalse($kitchen->can('canTransitionAsKitchen', [$order, OrderStatus::Confirmed]));

        $this->actingAs($kitchen);
        Volt::test('kitchen.orders.show', ['order' => $order])->call('transitionTo', 'confirmed');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_kitchen_cannot_transition_confirmed_to_ready(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $this->assertFalse($kitchen->can('canTransitionAsKitchen', [$order, OrderStatus::Ready]));

        $this->actingAs($kitchen);
        Volt::test('kitchen.orders.show', ['order' => $order])->call('transitionTo', 'ready');

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_kitchen_cannot_transition_confirmed_to_cancelled(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $this->assertFalse($kitchen->can('canTransitionAsKitchen', [$order, OrderStatus::Cancelled]));

        $this->actingAs($kitchen);
        Volt::test('kitchen.orders.show', ['order' => $order])->call('transitionTo', 'cancelled');

        $this->assertSame(OrderStatus::Confirmed, $order->fresh()->status);
    }

    public function test_kitchen_cannot_transition_ready_to_completed(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->assertFalse($kitchen->can('canTransitionAsKitchen', [$order, OrderStatus::Completed]));

        $this->actingAs($kitchen);
        Volt::test('kitchen.orders.show', ['order' => $order])->call('transitionTo', 'completed');

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
    }

    public function test_kitchen_cannot_transition_preparing_to_pending(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Preparing);

        $this->assertFalse($kitchen->can('canTransitionAsKitchen', [$order, OrderStatus::Pending]));

        $this->actingAs($kitchen);
        Volt::test('kitchen.orders.show', ['order' => $order])->call('transitionTo', 'pending');

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
    }

    // --- Existing owner/cashier behaviour is untouched (checklist 23) ----

    public function test_owner_order_access_still_works_unchanged(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant, OrderStatus::Pending);

        $this->actingAs($owner)->get(route('orders.index'))->assertOk();
        $this->actingAs($owner)->get(route('orders.show', $order))->assertOk();
    }

    public function test_owner_can_still_cancel_and_complete_orders_via_the_owner_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $pendingOrder = $this->createOrder($restaurant, OrderStatus::Pending);
        $readyOrder = $this->createOrder($restaurant, OrderStatus::Ready);

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $pendingOrder])
            ->call('transitionTo', 'cancelled')
            ->assertHasNoErrors();
        $this->assertSame(OrderStatus::Cancelled, $pendingOrder->fresh()->status);

        Volt::test('orders.show', ['order' => $readyOrder])
            ->call('transitionTo', 'completed')
            ->assertHasNoErrors();
        $this->assertSame(OrderStatus::Completed, $readyOrder->fresh()->status);
    }

    // --- Kitchen has no editing surface (checklist 24-26) -----------------

    public function test_kitchen_cannot_edit_order_items(): void
    {
        // No route, policy ability, or Livewire action exists for
        // editing order items from the kitchen interface at all - the
        // owner/cashier order pages have no item-editing UI either
        // (Phase 7 built order items as create-time-only), so there is
        // no endpoint for kitchen to reach in the first place.
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->assertFalse($kitchen->can('update', \App\Models\OrderItem::class));
    }

    public function test_kitchen_order_show_component_exposes_no_price_or_total_or_restaurant_id_properties(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, OrderStatus::Confirmed);

        $this->actingAs($kitchen);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('kitchen.orders.show', ['order' => $order])->instance())
        );

        $this->assertNotContains('subtotal', $publicProperties);
        $this->assertNotContains('total', $publicProperties);
        $this->assertNotContains('unit_price', $publicProperties);
        $this->assertNotContains('price', $publicProperties);
        $this->assertNotContains('restaurant_id', $publicProperties);
        $this->assertNotContains('customer_id', $publicProperties);
        $this->assertNotContains('items', $publicProperties);
    }

    public function test_kitchen_order_index_component_exposes_no_restaurant_id_property(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('kitchen.orders.index')->instance())
        );

        $this->assertNotContains('restaurant_id', $publicProperties);
    }

    // --- Tenant scope on direct Order queries (checklist 27) --------------

    public function test_tenant_isolation_still_works_through_direct_order_queries_when_tenant_context_is_set(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $orderA = $this->createOrder($restaurantA, OrderStatus::Confirmed);
        $this->createOrder($restaurantB, OrderStatus::Confirmed);

        app(TenantContext::class)->set($restaurantA);

        $this->assertSame([$orderA->id], Order::query()->pluck('id')->all());
    }
}
