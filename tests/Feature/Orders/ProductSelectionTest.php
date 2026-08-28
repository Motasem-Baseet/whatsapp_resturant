<?php

namespace Tests\Feature\Orders;

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

/**
 * Phase 15: shared product search / category filter / quantity-stepper
 * behavior (App\Livewire\Concerns\HasProductSelection), exercised
 * through both order-creation pages. Behavior already covered by the
 * pre-existing OrderManagementTest and ConversationOrderCreationTest
 * (access, ownership, snapshotting, price authority, duplicate
 * merging, tenant isolation) is not repeated here - both of those
 * suites were re-run against the refactored pages and still pass
 * unmodified.
 */
class ProductSelectionTest extends TestCase
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

    private function createProduct(Restaurant $restaurant, string $name, string $price = '5.00', bool $active = true, ?Category $category = null): Product
    {
        $category ??= Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);

        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => $name,
            'price' => $price,
            'is_active' => $active,
        ]);
    }

    private function createConversation(Restaurant $restaurant): Conversation
    {
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        return Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
    }

    // --- Product search (direct order page) ------------------------------

    public function test_owner_can_search_products_by_name(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, 'Classic Burger');
        $this->createProduct($restaurant, 'Veggie Wrap');

        $this->actingAs($owner);

        $names = Volt::test('orders.create')
            ->set('product_search', 'burger')
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame(['Classic Burger'], $names);
    }

    public function test_cashier_can_search_products_by_name(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $this->createProduct($restaurant, 'Iced Tea');
        $this->createProduct($restaurant, 'Lemonade');

        $this->actingAs($cashier);

        $names = Volt::test('orders.create')
            ->set('product_search', 'tea')
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame(['Iced Tea'], $names);
    }

    public function test_search_is_case_insensitive(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, 'Classic Burger');

        $this->actingAs($owner);

        $names = Volt::test('orders.create')
            ->set('product_search', 'BURGER')
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame(['Classic Burger'], $names);
    }

    public function test_search_does_not_show_another_restaurants_products(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurantA);
        $this->createProduct($restaurantA, 'Shared Name Burger');
        $this->createProduct($restaurantB, 'Shared Name Burger');

        $this->actingAs($owner);

        $products = Volt::test('orders.create')
            ->set('product_search', 'burger')
            ->instance()->availableProducts();

        $this->assertCount(1, $products);
        $this->assertSame($restaurantA->id, $products->first()->restaurant_id);
    }

    public function test_inactive_products_do_not_appear_in_search(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, 'Retired Burger', active: false);

        $this->actingAs($owner);

        $names = Volt::test('orders.create')
            ->set('product_search', 'burger')
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame([], $names);
    }

    public function test_products_in_inactive_categories_do_not_appear_in_search(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $inactiveCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => false]);
        $this->createProduct($restaurant, 'Orphaned Burger', category: $inactiveCategory);

        $this->actingAs($owner);

        $names = Volt::test('orders.create')
            ->set('product_search', 'burger')
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame([], $names);
    }

    public function test_no_search_term_returns_all_orderable_products(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, 'Burger');
        $this->createProduct($restaurant, 'Fries');

        $this->actingAs($owner);

        $names = Volt::test('orders.create')->instance()->availableProducts()->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Burger', 'Fries'], $names);
    }

    // --- Category filtering --------------------------------------------

    public function test_categories_offered_are_tenant_scoped(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurantA);
        Category::factory()->create(['restaurant_id' => $restaurantA->id, 'name' => 'Restaurant A Category', 'is_active' => true]);
        Category::factory()->create(['restaurant_id' => $restaurantB->id, 'name' => 'Restaurant B Category', 'is_active' => true]);

        $this->actingAs($owner);

        $names = Volt::test('orders.create')->instance()->availableCategories()->pluck('name')->all();

        $this->assertSame(['Restaurant A Category'], $names);
    }

    public function test_category_filter_shows_only_matching_products(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $drinks = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
        $food = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
        $this->createProduct($restaurant, 'Cola', category: $drinks);
        $this->createProduct($restaurant, 'Burger', category: $food);

        $this->actingAs($owner);

        $names = Volt::test('orders.create')
            ->set('category_id', (string) $drinks->id)
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame(['Cola'], $names);
    }

    public function test_a_foreign_category_id_cannot_reveal_foreign_products(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurantA);
        $categoryB = Category::factory()->create(['restaurant_id' => $restaurantB->id, 'is_active' => true]);
        $this->createProduct($restaurantB, 'Restaurant B Product', category: $categoryB);
        $this->createProduct($restaurantA, 'Restaurant A Product');

        $this->actingAs($owner);

        $products = Volt::test('orders.create')
            ->set('category_id', (string) $categoryB->id)
            ->instance()->availableProducts();

        // The foreign category id is not trusted - it is treated as no
        // category filter, never as a filter that could somehow match
        // restaurant B's rows against restaurant A's own products()
        // query.
        $this->assertFalse($products->contains('name', 'Restaurant B Product'));
        $this->assertTrue($products->contains('name', 'Restaurant A Product'));
    }

    public function test_an_invalid_category_filter_fails_safely(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        $names = Volt::test('orders.create')
            ->set('category_id', '999999')
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame(['Burger'], $names);
    }

    public function test_search_and_category_filter_work_together(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $drinks = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
        $food = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
        $this->createProduct($restaurant, 'Iced Tea', category: $drinks);
        $this->createProduct($restaurant, 'Hot Tea', category: $drinks);
        $this->createProduct($restaurant, 'Tea Sandwich', category: $food);

        $this->actingAs($owner);

        $names = Volt::test('orders.create')
            ->set('product_search', 'tea')
            ->set('category_id', (string) $drinks->id)
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertEqualsCanonicalizing(['Iced Tea', 'Hot Tea'], $names);
    }

    // --- Quantity controls -------------------------------------------

    public function test_adding_a_product_creates_one_selected_item(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        $items = Volt::test('orders.create')
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->get('items');

        $this->assertCount(1, $items);
        $this->assertSame(1, $items[$product->id]['quantity']);
    }

    public function test_selecting_the_same_product_again_merges_quantity_instead_of_duplicating(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        $items = Volt::test('orders.create')
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->get('items');

        $this->assertCount(1, $items);
        $this->assertSame(2, $items[$product->id]['quantity']);
    }

    public function test_incrementing_quantity_works(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        $items = Volt::test('orders.create')
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->call('incrementQuantity', $product->id)
            ->get('items');

        $this->assertSame(2, $items[$product->id]['quantity']);
    }

    public function test_decrementing_quantity_works(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        $items = Volt::test('orders.create')
            ->set('selected_product_id', (string) $product->id)
            ->set('selected_quantity', 3)
            ->call('addItem')
            ->call('decrementQuantity', $product->id)
            ->get('items');

        $this->assertSame(2, $items[$product->id]['quantity']);
    }

    public function test_quantity_cannot_go_below_one_via_decrement(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        $items = Volt::test('orders.create')
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->call('decrementQuantity', $product->id)
            ->call('decrementQuantity', $product->id)
            ->get('items');

        $this->assertSame(1, $items[$product->id]['quantity']);
    }

    public function test_a_forged_zero_quantity_item_is_rejected_by_create_order(): void
    {
        // CreateOrder::normalizeItems() drops any item with quantity < 1
        // before checking whether any items remain - with only one
        // forged item, that leaves nothing, so CreateOrder throws
        // (unchanged, pre-existing behavior) rather than silently
        // creating an empty order.
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        $this->expectException(InvalidArgumentException::class);

        try {
            Volt::test('orders.create')
                ->set('customer_id', (string) $customer->id)
                ->set('items', [$product->id => ['product_id' => $product->id, 'name' => 'Burger', 'price' => '5.00', 'quantity' => 0]])
                ->call('save');
        } finally {
            $this->assertDatabaseMissing('orders', ['customer_id' => $customer->id]);
        }
    }

    public function test_a_forged_negative_quantity_item_is_rejected_by_create_order(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        $this->expectException(InvalidArgumentException::class);

        try {
            Volt::test('orders.create')
                ->set('customer_id', (string) $customer->id)
                ->set('items', [$product->id => ['product_id' => $product->id, 'name' => 'Burger', 'price' => '5.00', 'quantity' => -5]])
                ->call('save');
        } finally {
            $this->assertDatabaseMissing('orders', ['customer_id' => $customer->id]);
        }
    }

    public function test_a_valid_forged_quantity_alongside_an_invalid_one_still_produces_a_correct_order(): void
    {
        // CreateOrder::normalizeItems() drops the invalid (<1 quantity)
        // line and keeps the valid one - this is the existing,
        // authoritative behavior (unchanged by this phase), exercised
        // here through the improved UI's own save() path.
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $good = $this->createProduct($restaurant, 'Burger', '5.00');
        $bad = $this->createProduct($restaurant, 'Fries', '2.00');

        $this->actingAs($owner);

        Volt::test('orders.create')
            ->set('customer_id', (string) $customer->id)
            ->set('items', [
                $good->id => ['product_id' => $good->id, 'name' => 'Burger', 'price' => '5.00', 'quantity' => 1],
                $bad->id => ['product_id' => $bad->id, 'name' => 'Fries', 'price' => '2.00', 'quantity' => 0],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $order = Order::where('customer_id', $customer->id)->firstOrFail();

        $this->assertCount(1, $order->items);
        $this->assertSame('Burger', $order->items->first()->product_name);
    }

    // --- Direct order creation: redirect + forgery through the new path ---

    public function test_direct_order_creation_redirects_to_the_order_detail_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        Volt::test('orders.create')
            ->set('customer_id', (string) $customer->id)
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->call('save')
            ->assertRedirect(route('orders.show', Order::where('customer_id', $customer->id)->firstOrFail()));
    }

    public function test_a_forged_foreign_product_in_items_is_rejected_on_the_direct_order_page(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurantA);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        $productB = $this->createProduct($restaurantB, 'Restaurant B Product');

        $this->actingAs($owner);

        $this->expectException(InvalidArgumentException::class);

        Volt::test('orders.create')
            ->set('customer_id', (string) $customer->id)
            ->set('items', [$productB->id => ['product_id' => $productB->id, 'name' => 'Restaurant B Product', 'price' => '5.00', 'quantity' => 1]])
            ->call('save');

        $this->assertDatabaseMissing('orders', ['customer_id' => $customer->id]);
    }

    // --- Conversation order creation: search + category filter -----------

    public function test_product_search_works_on_the_conversation_order_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $this->createProduct($restaurant, 'Classic Burger');
        $this->createProduct($restaurant, 'Veggie Wrap');

        $this->actingAs($owner);

        $names = Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('product_search', 'wrap')
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame(['Veggie Wrap'], $names);
    }

    public function test_category_filtering_works_on_the_conversation_order_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $drinks = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
        $this->createProduct($restaurant, 'Cola', category: $drinks);
        $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        $names = Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('category_id', (string) $drinks->id)
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame(['Cola'], $names);
    }

    public function test_conversation_order_creation_redirects_to_the_order_detail_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);
        $product = $this->createProduct($restaurant, 'Burger');

        $this->actingAs($owner);

        Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->set('selected_product_id', (string) $product->id)
            ->call('addItem')
            ->call('save')
            ->assertRedirect(route('orders.show', Order::where('conversation_id', $conversation->id)->firstOrFail()));
    }

    // --- Reuse / no new sensitive public state --------------------------

    public function test_the_shared_trait_introduces_no_sensitive_public_properties_on_the_direct_order_page(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(get_object_vars(Volt::test('orders.create')->instance()));

        foreach (['restaurant_id', 'created_by', 'price', 'unit_price', 'line_total', 'subtotal', 'total'] as $sensitive) {
            $this->assertNotContains($sensitive, $publicProperties);
        }
    }

    public function test_the_shared_trait_introduces_no_sensitive_public_properties_on_the_conversation_order_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->createConversation($restaurant);

        $this->actingAs($owner);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])->instance())
        );

        foreach (['restaurant_id', 'customer_id', 'created_by', 'price', 'unit_price', 'line_total', 'subtotal', 'total'] as $sensitive) {
            $this->assertNotContains($sensitive, $publicProperties);
        }
    }
}
