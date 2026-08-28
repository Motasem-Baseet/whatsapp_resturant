<?php

namespace Tests\Feature\Customers;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Inbox\MarkConversationAsRead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
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

    private function createOrder(Restaurant $restaurant, Customer $customer, array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
        ], $attributes));
    }

    // --- Route access -----------------------------------------------

    public function test_owner_can_access_a_customer_profile(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner)->get(route('customers.show', $customer))->assertOk();
    }

    public function test_cashier_can_access_a_customer_profile(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($cashier)->get(route('customers.show', $customer))->assertOk();
    }

    public function test_kitchen_receives_403_for_customer_profile(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($kitchen)->get(route('customers.show', $customer))->assertForbidden();
    }

    public function test_a_roleless_user_receives_403_for_customer_profile(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($user)->get(route('customers.show', $customer))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $customer = Customer::factory()->create();

        $this->get(route('customers.show', $customer))->assertRedirect(route('login'));
    }

    // --- Tenant isolation ------------------------------------------------

    public function test_restaurant_a_cannot_access_restaurant_bs_customer_profile(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);

        $this->actingAs($ownerA)->get(route('customers.show', $customerB))->assertNotFound();
    }

    public function test_restaurant_bs_orders_do_not_appear_in_restaurant_as_customer_profile(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        $this->createOrder($restaurantB, $customerB);

        $this->actingAs($ownerA);

        $ids = Volt::test('customers.show', ['customer' => $customerA])
            ->instance()->orders()->pluck('id')->all();

        $this->assertSame([], $ids);
    }

    public function test_restaurant_bs_conversations_do_not_appear_in_restaurant_as_customer_profile(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);

        $this->actingAs($ownerA);

        $ids = Volt::test('customers.show', ['customer' => $customerA])
            ->instance()->conversations()->pluck('id')->all();

        $this->assertSame([], $ids);
    }

    // --- Customer profile display -----------------------------------

    public function test_customer_information_displays_correctly(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Jane Doe',
            'phone' => '+962790000000',
            'notes' => 'Allergic to peanuts.',
        ]);

        $this->actingAs($owner);

        Volt::test('customers.show', ['customer' => $customer])
            ->assertSee('Jane Doe')
            ->assertSee('+962790000000')
            ->assertSee('Allergic to peanuts.');
    }

    public function test_order_history_displays_correctly_and_links_to_the_order_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $order = $this->createOrder($restaurant, $customer, ['status' => OrderStatus::Completed, 'total' => '42.00']);

        $this->actingAs($owner);

        Volt::test('customers.show', ['customer' => $customer])
            ->assertSee("Order #{$order->id}")
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_conversation_history_displays_correctly_and_links_to_the_conversation_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);

        Volt::test('customers.show', ['customer' => $customer])
            ->assertSee(route('conversations.show', $conversation), false);
    }

    public function test_another_customers_orders_and_conversations_do_not_appear(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $orderB = $this->createOrder($restaurant, $customerB);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customerB->id]);

        $this->actingAs($owner);

        $component = Volt::test('customers.show', ['customer' => $customerA]);

        $this->assertNotContains($orderB->id, $component->instance()->orders()->pluck('id')->all());
        $this->assertNotContains($conversationB->id, $component->instance()->conversations()->pluck('id')->all());
    }

    // --- Statistics ----------------------------------------------------

    public function test_total_orders_statistic_is_correct(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $this->createOrder($restaurant, $customer);
        $this->createOrder($restaurant, $customer);

        $this->actingAs($owner);

        $this->assertSame(2, Volt::test('customers.show', ['customer' => $customer])->instance()->stats()['total_orders']);
    }

    public function test_completed_orders_statistic_is_correct(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $this->createOrder($restaurant, $customer, ['status' => OrderStatus::Completed]);
        $this->createOrder($restaurant, $customer, ['status' => OrderStatus::Pending]);

        $this->actingAs($owner);

        $this->assertSame(1, Volt::test('customers.show', ['customer' => $customer])->instance()->stats()['completed_orders']);
    }

    public function test_total_spent_excludes_cancelled_orders(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $this->createOrder($restaurant, $customer, ['status' => OrderStatus::Completed, 'total' => '10.00']);
        $this->createOrder($restaurant, $customer, ['status' => OrderStatus::Cancelled, 'total' => '50.00']);

        $this->actingAs($owner);

        $this->assertSame('10.00', Volt::test('customers.show', ['customer' => $customer])->instance()->stats()['total_spent']);
    }

    public function test_total_spent_includes_non_cancelled_statuses(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $this->createOrder($restaurant, $customer, ['status' => OrderStatus::Pending, 'total' => '5.00']);
        $this->createOrder($restaurant, $customer, ['status' => OrderStatus::Completed, 'total' => '15.00']);

        $this->actingAs($owner);

        $this->assertSame('20.00', Volt::test('customers.show', ['customer' => $customer])->instance()->stats()['total_spent']);
    }

    public function test_conversation_count_statistic_is_correct(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);

        $this->assertSame(2, Volt::test('customers.show', ['customer' => $customer])->instance()->stats()['conversation_count']);
    }

    public function test_statistics_for_a_customer_with_no_activity_are_zero_and_safe(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner);

        $stats = Volt::test('customers.show', ['customer' => $customer])->instance()->stats();

        $this->assertSame(0, $stats['total_orders']);
        $this->assertSame(0, $stats['completed_orders']);
        $this->assertSame('0.00', $stats['total_spent']);
        $this->assertSame(0, $stats['conversation_count']);
        $this->assertNull($stats['latest_order_at']);
        $this->assertNull($stats['latest_conversation_at']);
    }

    // --- Unread state reuse ------------------------------------------

    public function test_an_unread_conversation_is_flagged_for_the_current_user_only(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
        ]);

        app(MarkConversationAsRead::class)->handle($conversation, $owner);

        $this->actingAs($owner);
        $ownerUnread = Volt::test('customers.show', ['customer' => $customer])->instance()->unreadConversationIds();

        $this->actingAs($cashier);
        $cashierUnread = Volt::test('customers.show', ['customer' => $customer])->instance()->unreadConversationIds();

        $this->assertNotContains($conversation->id, $ownerUnread);
        $this->assertContains($conversation->id, $cashierUnread);
    }

    // --- Authorization -------------------------------------------------

    public function test_policy_authorization_is_enforced_when_mounting_the_component_directly(): void
    {
        // Volt::test() catches AuthorizationException internally
        // (rendering the component's own 403 response rather than
        // letting it surface as a raw PHP exception here, a
        // well-established quirk of this test harness) - the real
        // response status is still observable via the test instance.
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($kitchen);

        Volt::test('customers.show', ['customer' => $customer])->assertForbidden();
    }

    // --- Public state / reflection --------------------------------------

    public function test_the_profile_component_exposes_no_restaurant_id_property(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('customers.show', ['customer' => $customer])->instance())
        );

        $this->assertNotContains('restaurant_id', $publicProperties);
        $this->assertNotContains('tenant_id', $publicProperties);
    }

    // --- Performance / eager loading -------------------------------------

    public function test_conversation_assigned_user_relationship_is_eager_loaded(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        Conversation::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'assigned_user_id' => $owner->id,
        ]);

        $this->actingAs($owner);

        $conversation = Volt::test('customers.show', ['customer' => $customer])
            ->instance()->conversations()->first();

        $this->assertTrue($conversation->relationLoaded('assignedUser'));
    }

    // --- Customer list pagination ----------------------------------------

    public function test_the_customer_list_is_paginated(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        Customer::factory()->count(20)->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner);

        $page = Volt::test('customers.index')->instance()->customers();

        $this->assertSame(15, $page->perPage());
        $this->assertSame(20, $page->total());
        $this->assertCount(15, $page->items());
    }
}
