<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Orders\UpdateOrderStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OrderStatusHistoryTest extends TestCase
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

    // --- Recording on success/failure ---------------------------------

    public function test_a_successful_transition_records_a_history_row_with_the_correct_from_to_and_actor(): void
    {
        $order = $this->createOrder(OrderStatus::Pending);
        $user = User::factory()->create(['restaurant_id' => $order->restaurant_id]);
        $user->assignRole(Role::findOrCreate('owner'));

        app(UpdateOrderStatus::class)->handle($order, OrderStatus::Confirmed, $user);

        $this->assertDatabaseCount('order_status_histories', 1);

        $history = OrderStatusHistory::first();
        $this->assertSame($order->id, $history->order_id);
        $this->assertSame($order->restaurant_id, $history->restaurant_id);
        $this->assertSame(OrderStatus::Pending->value, $history->from_status);
        $this->assertSame(OrderStatus::Confirmed->value, $history->to_status);
        $this->assertSame($user->id, $history->changed_by);
    }

    public function test_a_transition_with_no_acting_user_records_a_null_changed_by(): void
    {
        $order = $this->createOrder(OrderStatus::Pending);

        app(UpdateOrderStatus::class)->handle($order, OrderStatus::Confirmed);

        $history = OrderStatusHistory::first();
        $this->assertNull($history->changed_by);
    }

    public function test_a_failed_transition_records_no_history_row(): void
    {
        $order = $this->createOrder(OrderStatus::Pending);

        try {
            app(UpdateOrderStatus::class)->handle($order, OrderStatus::Ready);
        } catch (InvalidOrderStatusTransitionException) {
            // expected
        }

        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public function test_repeated_valid_transitions_each_record_their_own_row_with_no_duplicates(): void
    {
        $order = $this->createOrder(OrderStatus::Pending);

        app(UpdateOrderStatus::class)->handle($order, OrderStatus::Confirmed);
        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Preparing);
        app(UpdateOrderStatus::class)->handle($order->fresh(), OrderStatus::Ready);

        $this->assertDatabaseCount('order_status_histories', 3);

        $sequence = OrderStatusHistory::orderBy('id')->pluck('to_status')->all();
        $this->assertSame([
            OrderStatus::Confirmed->value,
            OrderStatus::Preparing->value,
            OrderStatus::Ready->value,
        ], $sequence);
    }

    public function test_history_survives_the_actor_later_being_deactivated(): void
    {
        $order = $this->createOrder(OrderStatus::Pending);
        $user = User::factory()->create(['restaurant_id' => $order->restaurant_id, 'is_active' => true]);
        $user->assignRole(Role::findOrCreate('owner'));

        app(UpdateOrderStatus::class)->handle($order, OrderStatus::Confirmed, $user);

        // is_active is not mass-assignable (see User::$fillable), so it
        // must be set directly - matching the same convention that
        // model deliberately enforces everywhere else.
        $user->is_active = false;
        $user->save();

        $history = OrderStatusHistory::with('changedBy')->first();
        $this->assertSame($user->id, $history->changedBy->id);
        $this->assertFalse($history->changedBy->is_active);
    }

    // --- Livewire entry points wire the acting user through -----------

    public function test_the_owner_cashier_order_page_records_the_authenticated_user_as_the_actor(): void
    {
        $order = $this->createOrder(OrderStatus::Pending);
        $owner = User::factory()->create(['restaurant_id' => $order->restaurant_id]);
        $owner->assignRole(Role::findOrCreate('owner'));

        $this->actingAs($owner);

        Volt::test('orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Confirmed->value);

        $this->assertSame($owner->id, OrderStatusHistory::first()->changed_by);
    }

    public function test_the_kitchen_order_page_records_the_authenticated_kitchen_user_as_the_actor(): void
    {
        $order = $this->createOrder(OrderStatus::Confirmed);
        $kitchen = User::factory()->create(['restaurant_id' => $order->restaurant_id]);
        $kitchen->assignRole(Role::findOrCreate('kitchen'));

        $this->actingAs($kitchen);

        Volt::test('kitchen.orders.show', ['order' => $order])
            ->call('transitionTo', OrderStatus::Preparing->value);

        $this->assertSame($kitchen->id, OrderStatusHistory::first()->changed_by);
    }

    // --- Display on the existing order detail page ---------------------

    public function test_the_order_detail_page_displays_recorded_status_history(): void
    {
        $order = $this->createOrder(OrderStatus::Pending);
        $owner = User::factory()->create(['restaurant_id' => $order->restaurant_id, 'name' => 'Pat Owner']);
        $owner->assignRole(Role::findOrCreate('owner'));

        app(UpdateOrderStatus::class)->handle($order, OrderStatus::Confirmed, $owner);

        $this->actingAs($owner);

        $component = Volt::test('orders.show', ['order' => $order->fresh()]);

        $history = $component->instance()->statusHistory();
        $this->assertCount(1, $history);
        $this->assertSame('Pat Owner', $history->first()->changedBy->name);
    }

    // --- Multi-tenant integrity -----------------------------------------

    public function test_a_history_row_cannot_reference_an_order_from_a_different_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $order = $this->createOrder(OrderStatus::Pending, $restaurantA);

        $this->expectException(QueryException::class);

        DB::table('order_status_histories')->insert([
            'restaurant_id' => $restaurantB->id,
            'order_id' => $order->id,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Confirmed->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_history_row_cannot_reference_a_changed_by_user_from_a_different_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $order = $this->createOrder(OrderStatus::Pending, $restaurantA);
        $foreignUser = User::factory()->create(['restaurant_id' => $restaurantB->id]);

        $this->expectException(QueryException::class);

        DB::table('order_status_histories')->insert([
            'restaurant_id' => $restaurantA->id,
            'order_id' => $order->id,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Confirmed->value,
            'changed_by' => $foreignUser->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_matching_history_row_can_be_inserted_directly(): void
    {
        $restaurant = Restaurant::factory()->create();
        $order = $this->createOrder(OrderStatus::Pending, $restaurant);
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);

        DB::table('order_status_histories')->insert([
            'restaurant_id' => $restaurant->id,
            'order_id' => $order->id,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Confirmed->value,
            'changed_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'changed_by' => $user->id]);
    }

    public function test_a_user_cannot_view_status_history_for_another_restaurants_order(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $order = $this->createOrder(OrderStatus::Pending, $restaurantA);
        $foreignOwner = User::factory()->create(['restaurant_id' => $restaurantB->id]);
        $foreignOwner->assignRole(Role::findOrCreate('owner'));

        $this->actingAs($foreignOwner)->get(route('orders.show', $order))->assertNotFound();
    }
}
