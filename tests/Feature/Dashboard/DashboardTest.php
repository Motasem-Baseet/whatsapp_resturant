<?php

namespace Tests\Feature\Dashboard;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Inbox\MarkConversationAsRead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    private function createProduct(Restaurant $restaurant, string $price = '5.00'): Product
    {
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);

        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'price' => $price,
            'is_active' => true,
        ]);
    }

    private function createOrder(Restaurant $restaurant, array $attributes = []): Order
    {
        $customerId = $attributes['customer_id']
            ?? Customer::factory()->create(['restaurant_id' => $restaurant->id])->id;

        return Order::factory()->create(array_merge([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customerId,
        ], $attributes));
    }

    // --- Dashboard access ---------------------------------------------

    public function test_owner_can_access_the_dashboard(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    public function test_cashier_can_access_the_dashboard(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier)->get(route('dashboard'))->assertOk();
    }

    public function test_kitchen_can_access_the_dashboard(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen)->get(route('dashboard'))->assertOk();
    }

    public function test_a_roleless_user_sees_no_restaurant_data(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Pending]);

        $this->actingAs($user);

        $component = Volt::test('dashboard');

        $component->assertOk();
        $this->assertNull($component->instance()->metrics());
    }

    // --- Owner/cashier metrics ------------------------------------------

    public function test_todays_orders_count_is_correct_and_excludes_previous_days(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createOrder($restaurant, ['created_at' => now()]);
        $this->createOrder($restaurant, ['created_at' => now()]);
        $this->createOrder($restaurant, ['created_at' => now()->subDay()]);

        $this->actingAs($owner);

        $this->assertSame(2, Volt::test('dashboard')->instance()->metrics()['todays_orders']);
    }

    public function test_todays_orders_count_excludes_other_restaurants(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurantA);
        $this->createOrder($restaurantA, ['created_at' => now()]);
        $this->createOrder($restaurantB, ['created_at' => now()]);
        $this->createOrder($restaurantB, ['created_at' => now()]);

        $this->actingAs($owner);

        $this->assertSame(1, Volt::test('dashboard')->instance()->metrics()['todays_orders']);
    }

    public function test_todays_revenue_sums_todays_order_totals(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createOrder($restaurant, ['created_at' => now(), 'total' => '10.00']);
        $this->createOrder($restaurant, ['created_at' => now(), 'total' => '15.50']);
        $this->createOrder($restaurant, ['created_at' => now()->subDay(), 'total' => '999.00']);

        $this->actingAs($owner);

        $this->assertSame('25.50', Volt::test('dashboard')->instance()->metrics()['todays_revenue']);
    }

    public function test_todays_revenue_excludes_cancelled_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createOrder($restaurant, ['created_at' => now(), 'total' => '10.00', 'status' => OrderStatus::Completed]);
        $this->createOrder($restaurant, ['created_at' => now(), 'total' => '20.00', 'status' => OrderStatus::Cancelled]);

        $this->actingAs($owner);

        $this->assertSame('10.00', Volt::test('dashboard')->instance()->metrics()['todays_revenue']);
    }

    public function test_todays_revenue_includes_completed_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createOrder($restaurant, ['created_at' => now(), 'total' => '42.00', 'status' => OrderStatus::Completed]);

        $this->actingAs($owner);

        $this->assertSame('42.00', Volt::test('dashboard')->instance()->metrics()['todays_revenue']);
    }

    public function test_pending_orders_count_is_correct(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createOrder($restaurant, ['status' => OrderStatus::Pending]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Pending]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Confirmed]);

        $this->actingAs($owner);

        $this->assertSame(2, Volt::test('dashboard')->instance()->metrics()['pending_orders']);
    }

    public function test_active_kitchen_orders_count_includes_confirmed_preparing_and_ready_only(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createOrder($restaurant, ['status' => OrderStatus::Confirmed]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Preparing]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Ready]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Pending]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Completed]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Cancelled]);

        $this->actingAs($owner);

        $this->assertSame(3, Volt::test('dashboard')->instance()->metrics()['active_kitchen_orders']);
    }

    public function test_todays_new_customers_count_is_correct(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'created_at' => now()]);
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'created_at' => now()]);
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'created_at' => now()->subDay()]);

        $this->actingAs($owner);

        $this->assertSame(2, Volt::test('dashboard')->instance()->metrics()['todays_new_customers']);
    }

    public function test_unread_conversation_count_is_specific_to_the_authenticated_user(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        \App\Models\Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => \App\Enums\MessageDirection::Inbound,
        ]);

        app(MarkConversationAsRead::class)->handle($conversation, $owner);

        $this->actingAs($owner);
        $ownerUnread = Volt::test('dashboard')->instance()->metrics()['unread_conversations'];

        $this->actingAs($cashier);
        $cashierUnread = Volt::test('dashboard')->instance()->metrics()['unread_conversations'];

        $this->assertSame(0, $ownerUnread);
        $this->assertSame(1, $cashierUnread);
    }

    // --- Recent orders ---------------------------------------------------

    public function test_recent_orders_shows_current_restaurant_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $order = $this->createOrder($restaurant);

        $this->actingAs($owner);

        $ids = Volt::test('dashboard')->instance()->metrics()['recent_orders']->pluck('id')->all();

        $this->assertContains($order->id, $ids);
    }

    public function test_recent_orders_excludes_other_restaurants(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurantA);
        $orderB = $this->createOrder($restaurantB);

        $this->actingAs($owner);

        $ids = Volt::test('dashboard')->instance()->metrics()['recent_orders']->pluck('id')->all();

        $this->assertNotContains($orderB->id, $ids);
    }

    public function test_recent_orders_are_ordered_newest_first(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $older = $this->createOrder($restaurant, ['created_at' => now()->subHours(2)]);
        $newer = $this->createOrder($restaurant, ['created_at' => now()]);

        $this->actingAs($owner);

        $ids = Volt::test('dashboard')->instance()->metrics()['recent_orders']->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_recent_orders_are_limited_to_five(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        for ($i = 0; $i < 7; $i++) {
            $this->createOrder($restaurant);
        }

        $this->actingAs($owner);

        $this->assertCount(5, Volt::test('dashboard')->instance()->metrics()['recent_orders']);
    }

    public function test_recent_orders_customer_relationship_is_eager_loaded(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createOrder($restaurant);

        $this->actingAs($owner);

        $order = Volt::test('dashboard')->instance()->metrics()['recent_orders']->first();

        $this->assertTrue($order->relationLoaded('customer'));
    }

    // --- Recent conversations ----------------------------------------

    public function test_recent_conversations_shows_current_restaurant_conversations(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);

        $ids = Volt::test('dashboard')->instance()->metrics()['recent_conversations']->pluck('id')->all();

        $this->assertContains($conversation->id, $ids);
    }

    public function test_recent_conversations_excludes_other_restaurants(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurantA);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);

        $this->actingAs($owner);

        $ids = Volt::test('dashboard')->instance()->metrics()['recent_conversations']->pluck('id')->all();

        $this->assertNotContains($conversationB->id, $ids);
    }

    public function test_recent_conversations_unread_state_is_correct_for_the_current_user(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $readConversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customerA->id]);
        $unreadConversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customerB->id]);

        foreach ([$readConversation, $unreadConversation] as $conversation) {
            \App\Models\Message::factory()->create([
                'restaurant_id' => $restaurant->id,
                'conversation_id' => $conversation->id,
                'direction' => \App\Enums\MessageDirection::Inbound,
            ]);
        }

        app(MarkConversationAsRead::class)->handle($readConversation, $owner);

        $this->actingAs($owner);

        $unreadIds = Volt::test('dashboard')->instance()->metrics()['unread_conversation_ids'];

        $this->assertContains($unreadConversation->id, $unreadIds);
        $this->assertNotContains($readConversation->id, $unreadIds);
    }

    // --- Kitchen dashboard -------------------------------------------

    public function test_kitchen_sees_confirmed_preparing_and_ready_counts(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $this->createOrder($restaurant, ['status' => OrderStatus::Confirmed]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Confirmed]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Preparing]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Ready]);

        $this->actingAs($kitchen);

        $metrics = Volt::test('dashboard')->instance()->metrics();

        $this->assertSame(2, $metrics['confirmed_count']);
        $this->assertSame(1, $metrics['preparing_count']);
        $this->assertSame(1, $metrics['ready_count']);
    }

    public function test_kitchen_dashboard_excludes_pending_completed_and_cancelled_from_counts(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $this->createOrder($restaurant, ['status' => OrderStatus::Pending]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Completed]);
        $this->createOrder($restaurant, ['status' => OrderStatus::Cancelled]);

        $this->actingAs($kitchen);

        $metrics = Volt::test('dashboard')->instance()->metrics();

        $this->assertSame(0, $metrics['confirmed_count']);
        $this->assertSame(0, $metrics['preparing_count']);
        $this->assertSame(0, $metrics['ready_count']);
        $this->assertCount(0, $metrics['recent_orders']);
    }

    public function test_kitchen_metrics_contain_no_revenue_or_customer_or_inbox_data(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen);

        $metrics = Volt::test('dashboard')->instance()->metrics();

        foreach (['todays_revenue', 'todays_new_customers', 'unread_conversations', 'recent_conversations', 'pending_orders'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $metrics);
        }
    }

    public function test_the_kitchen_view_does_not_render_revenue_or_customer_or_inbox_cards(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen);

        Volt::test('dashboard')
            ->assertDontSee("Today's revenue")
            ->assertDontSee("Today's new customers")
            ->assertDontSee('My unread conversations')
            ->assertDontSee('Recent conversations');
    }

    public function test_kitchen_recent_orders_are_tenant_scoped(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurantA, 'kitchen');
        $this->createOrder($restaurantA, ['status' => OrderStatus::Confirmed]);
        $orderB = $this->createOrder($restaurantB, ['status' => OrderStatus::Confirmed]);

        $this->actingAs($kitchen);

        $ids = Volt::test('dashboard')->instance()->metrics()['recent_orders']->pluck('id')->all();

        $this->assertNotContains($orderB->id, $ids);
    }

    public function test_kitchen_dashboard_links_point_to_kitchen_order_routes(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $order = $this->createOrder($restaurant, ['status' => OrderStatus::Confirmed]);

        $this->actingAs($kitchen);

        Volt::test('dashboard')
            ->assertSee(route('kitchen.orders.show', $order), false)
            ->assertDontSee(route('orders.show', $order), false);
    }

    // --- Cross-restaurant safety across every metric at once --------------

    public function test_restaurant_a_dashboard_never_reflects_restaurant_b_activity(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);

        // Heavy activity in restaurant B only.
        for ($i = 0; $i < 5; $i++) {
            $this->createOrder($restaurantB, ['created_at' => now(), 'total' => '100.00', 'status' => OrderStatus::Completed]);
        }
        Customer::factory()->count(3)->create(['restaurant_id' => $restaurantB->id, 'created_at' => now()]);

        $this->actingAs($ownerA);

        $metrics = Volt::test('dashboard')->instance()->metrics();

        $this->assertSame(0, $metrics['todays_orders']);
        $this->assertSame('0.00', $metrics['todays_revenue']);
        $this->assertSame(0, $metrics['todays_new_customers']);
        $this->assertCount(0, $metrics['recent_orders']);
    }
}
