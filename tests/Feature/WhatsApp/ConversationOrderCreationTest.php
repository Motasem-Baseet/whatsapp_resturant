<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Category;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConversationOrderCreationTest extends TestCase
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

    private function createProduct(Restaurant $restaurant, string $name, string $price, bool $active = true, bool $categoryActive = true): Product
    {
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => $categoryActive]);

        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => $name,
            'price' => $price,
            'is_active' => $active,
        ]);
    }

    private function createConversation(Restaurant $restaurant, ?Customer $customer = null): Conversation
    {
        $customer ??= Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        return Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
    }

    // --- Access -----------------------------------------------------------

    public function test_owner_can_access_the_conversation_order_workflow(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);

        $this->actingAs($owner)->get(route('conversations.orders.create', $conversation))->assertOk();
    }

    public function test_cashier_can_access_the_conversation_order_workflow(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $conversation = $this->createConversation($restaurant);

        $this->actingAs($cashier)->get(route('conversations.orders.create', $conversation))->assertOk();
    }

    public function test_kitchen_cannot_access_the_conversation_order_workflow(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $conversation = $this->createConversation($restaurant);

        $this->actingAs($kitchen)->get(route('conversations.orders.create', $conversation))->assertForbidden();
    }

    public function test_cross_tenant_conversation_order_route_returns_404(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $conversationB = $this->createConversation($restaurantB);

        $this->actingAs($ownerA)->get(route('conversations.orders.create', $conversationB))->assertNotFound();
    }

    // --- Order creation -----------------------------------------------

    public function test_owner_can_create_an_order_from_a_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Classic Burger', '5.00');

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->set('selected_quantity', 2)
            ->call('addItem')
            ->call('save')
            ->assertHasNoErrors();

        $order = Order::where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame('10.00', (string) $order->total);
    }

    public function test_cashier_can_create_an_order_from_a_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Fries', '3.00');

        $this->actingAs($cashier);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->set('selected_quantity', 1)
            ->call('addItem')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('orders', ['conversation_id' => $conversation->id, 'restaurant_id' => $restaurant->id]);
    }

    public function test_the_created_order_belongs_to_the_conversations_customer(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = $this->createConversation($restaurant, $customer);
        $product = $this->createProduct($restaurant, 'Burger', '5.00');

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->call('save');

        $order = Order::where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame($customer->id, $order->customer_id);
    }

    public function test_the_created_order_belongs_to_the_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Burger', '5.00');

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->call('save');

        $order = Order::where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame($conversation->id, $order->conversation_id);
    }

    public function test_the_created_order_belongs_to_the_authenticated_users_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Burger', '5.00');

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->call('save');

        $order = Order::where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame($restaurant->id, $order->restaurant_id);
    }

    public function test_created_by_is_the_authenticated_user(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Burger', '5.00');

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->call('save');

        $order = Order::where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame($owner->id, $order->created_by);
    }

    // --- Product behavior -------------------------------------------------

    public function test_only_current_restaurant_products_are_offered(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurantA);
        $conversation = $this->createConversation($restaurantA);
        $this->createProduct($restaurantA, 'Restaurant A Item', '5.00');
        $this->createProduct($restaurantB, 'Restaurant B Item', '5.00');

        $this->actingAs($owner);

        $names = Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame(['Restaurant A Item'], $names);
    }

    public function test_an_inactive_product_cannot_be_added(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Retired Item', '5.00', active: false);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->assertHasErrors(['selected_product_id']);
    }

    public function test_a_product_in_an_inactive_category_cannot_be_added(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Orphaned Item', '5.00', active: true, categoryActive: false);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->assertHasErrors(['selected_product_id']);
    }

    public function test_product_prices_are_derived_server_side_not_from_the_client(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Burger', '5.00');

        $this->actingAs($owner);

        // Forge the estimated display price and even a 'total' key on
        // the item array - save() never reads anything but product_id
        // and quantity from $this->items when calling CreateOrder.
        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('items', [
                $product->id => ['product_id' => $product->id, 'name' => 'Burger', 'price' => '0.01', 'total' => '0.01', 'quantity' => 1],
            ])
            ->call('save');

        $order = Order::where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame('5.00', (string) $order->items->first()->unit_price);
        $this->assertSame('5.00', (string) $order->total);
    }

    public function test_product_snapshot_remains_correct(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Classic Burger', '5.00');

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->call('save');

        $item = Order::where('conversation_id', $conversation->id)->firstOrFail()->items->first();

        $this->assertSame('Classic Burger', $item->product_name);
        $this->assertSame($product->id, $item->product_id);
    }

    public function test_duplicate_products_merge_into_a_single_line_item(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Burger', '5.00');

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->set('selected_quantity', 2)
            ->call('addItem')
            ->set('selected_product_id', (string) $product->id)
            ->set('selected_quantity', 3)
            ->call('addItem')
            ->call('save');

        $order = Order::where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertCount(1, $order->items);
        $this->assertSame(5, $order->items->first()->quantity);
        $this->assertSame('25.00', (string) $order->items->first()->line_total);
    }

    // --- Manipulation resistance -------------------------------------

    public function test_the_component_exposes_no_sensitive_public_properties(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);

        $this->actingAs($owner);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])->instance())
        );

        foreach (['restaurant_id', 'customer_id', 'conversation_id', 'created_by', 'price', 'unit_price', 'line_total', 'subtotal', 'total'] as $sensitive) {
            $this->assertNotContains($sensitive, $publicProperties);
        }
    }

    public function test_a_forged_customer_id_property_does_not_exist_to_manipulate(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $otherCustomer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Burger', '5.00');

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->call('save');

        $order = Order::where('conversation_id', $conversation->id)->firstOrFail();

        // The order's customer is always the conversation's own
        // customer - there is no writable property that could have
        // redirected it to $otherCustomer.
        $this->assertSame($conversation->customer_id, $order->customer_id);
        $this->assertNotSame($otherCustomer->id, $order->customer_id);
    }

    public function test_a_product_from_another_restaurant_is_rejected(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurantA);
        $conversation = $this->createConversation($restaurantA);
        $productB = $this->createProduct($restaurantB, 'Restaurant B Item', '5.00');

        $this->actingAs($owner);

        $this->expectException(InvalidArgumentException::class);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('items', [
                $productB->id => ['product_id' => $productB->id, 'name' => 'Restaurant B Item', 'price' => '5.00', 'quantity' => 1],
            ])
            ->call('save');

        $this->assertDatabaseMissing('orders', ['conversation_id' => $conversation->id]);
    }

    // --- Conversation order history ---------------------------------------

    public function test_orders_for_the_conversation_are_displayed(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $order = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
        ]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->assertSee("Order #{$order->id}");
    }

    public function test_orders_from_another_conversation_are_not_displayed(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversationA = $this->createConversation($restaurant);
        $conversationB = $this->createConversation($restaurant);
        $orderB = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $conversationB->customer_id,
            'conversation_id' => $conversationB->id,
        ]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversationA])
            ->assertDontSee("Order #{$orderB->id}");
    }

    public function test_orders_from_another_restaurant_are_not_displayed(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurantA);
        $conversationA = $this->createConversation($restaurantA);
        $conversationB = $this->createConversation($restaurantB);
        $orderB = Order::factory()->create([
            'restaurant_id' => $restaurantB->id,
            'customer_id' => $conversationB->customer_id,
            'conversation_id' => $conversationB->id,
        ]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversationA])
            ->assertDontSee("Order #{$orderB->id}");
    }

    public function test_multiple_orders_can_belong_to_the_same_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);

        $orderOne = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
        ]);
        $orderTwo = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
        ]);

        $this->actingAs($owner);

        $ids = Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->instance()->orders()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$orderOne->id, $orderTwo->id], $ids);
    }

    public function test_conversation_orders_link_to_the_order_detail_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $order = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $conversation->customer_id,
            'conversation_id' => $conversation->id,
        ]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->assertSee(route('orders.show', $order), false);
    }
}
