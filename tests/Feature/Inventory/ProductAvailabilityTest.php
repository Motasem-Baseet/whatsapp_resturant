<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 27: Product::isOrderable(), the product management UI (stock
 * display, status badges, quick availability toggle), and the product
 * selector's exclusion of unavailable/out-of-stock products.
 */
class ProductAvailabilityTest extends TestCase
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

    private function createProduct(Restaurant $restaurant, array $attributes = []): Product
    {
        $category = Category::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => $attributes['category_active'] ?? true,
        ]);
        unset($attributes['category_active']);

        return Product::factory()->create(array_merge([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_active' => true,
        ], $attributes));
    }

    // --- Product::isOrderable() ----------------------------------------------

    public function test_active_available_in_stock_product_is_orderable(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => 5]);

        $this->assertTrue($product->isOrderable());
    }

    public function test_active_available_untracked_stock_product_is_orderable(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => null]);

        $this->assertTrue($product->isOrderable());
    }

    public function test_an_unavailable_product_is_not_orderable(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['is_available' => false, 'stock_quantity' => 100]);

        $this->assertFalse($product->isOrderable());
    }

    public function test_an_inactive_product_is_not_orderable_even_if_available_and_in_stock(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['is_active' => false, 'is_available' => true, 'stock_quantity' => 100]);

        $this->assertFalse($product->isOrderable());
    }

    public function test_a_zero_stock_product_is_not_orderable(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['stock_quantity' => 0]);

        $this->assertFalse($product->isOrderable());
    }

    public function test_a_product_in_an_inactive_category_is_not_orderable(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['category_active' => false, 'stock_quantity' => 5]);

        $this->assertFalse($product->isOrderable());
    }

    /**
     * Positive stock never overrides an explicit unavailable state -
     * the two conditions are independent, and both must hold.
     */
    public function test_positive_stock_does_not_override_an_explicit_unavailable_state(): void
    {
        $restaurant = Restaurant::factory()->create();
        $product = $this->createProduct($restaurant, ['is_available' => false, 'stock_quantity' => 999]);

        $this->assertFalse($product->isOrderable());
    }

    // --- Stock management (edit page) ---------------------------------------

    public function test_owner_can_update_stock_quantity(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 5]);

        $this->actingAs($owner);

        Volt::test('menu.products.edit', ['product' => $product])
            ->set('stock_quantity', '20')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(20, $product->fresh()->stock_quantity);
    }

    public function test_negative_stock_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 5]);

        $this->actingAs($owner);

        Volt::test('menu.products.edit', ['product' => $product])
            ->set('stock_quantity', '-1')
            ->call('save')
            ->assertHasErrors(['stock_quantity']);

        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_zero_stock_is_accepted_and_stored_as_a_real_zero_not_null(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 5]);

        $this->actingAs($owner);

        Volt::test('menu.products.edit', ['product' => $product])
            ->set('stock_quantity', '0')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    /**
     * Leaving the stock field blank while editing an unrelated field
     * must preserve the product's untracked (null) state, not silently
     * coerce it to zero.
     */
    public function test_leaving_stock_blank_preserves_the_untracked_state(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => null, 'name' => 'Original Name']);

        $this->actingAs($owner);

        Volt::test('menu.products.edit', ['product' => $product])
            ->set('name', 'Updated Name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($product->fresh()->stock_quantity);
        $this->assertSame('Updated Name', $product->fresh()->name);
    }

    public function test_cross_tenant_stock_update_is_blocked(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $productB = $this->createProduct($restaurantB, ['stock_quantity' => 5]);

        $response = $this->actingAs($ownerA)->get(route('menu.products.edit', $productB));

        $response->assertNotFound();
        $this->assertSame(5, $productB->fresh()->stock_quantity);
    }

    public function test_stock_update_reauthorizes_at_action_time_not_just_mount(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 5]);

        $this->actingAs($owner);

        $component = Volt::test('menu.products.edit', ['product' => $product]);
        $owner->removeRole('owner');

        $component->set('stock_quantity', '99')->call('save');

        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    // --- Quick availability toggle -------------------------------------------

    public function test_owner_can_toggle_a_product_to_unavailable(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, ['is_available' => true]);

        $this->actingAs($owner);

        Volt::test('menu.products.index')->call('toggleAvailability', $product->id);

        $this->assertFalse($product->fresh()->is_available);
    }

    public function test_owner_can_toggle_a_product_back_to_available(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, ['is_available' => false]);

        $this->actingAs($owner);

        Volt::test('menu.products.index')->call('toggleAvailability', $product->id);

        $this->assertTrue($product->fresh()->is_available);
    }

    public function test_toggle_is_tenant_scoped_and_rejects_a_foreign_product_id(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $productB = $this->createProduct($restaurantB, ['is_available' => true]);

        $this->actingAs($ownerA);

        try {
            Volt::test('menu.products.index')->call('toggleAvailability', $productB->id);
            $this->fail('Expected a 404 for a foreign product id.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            // expected
        }

        $this->assertTrue($productB->fresh()->is_available);
    }

    public function test_toggle_reauthorizes_at_action_time_not_just_mount(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, ['is_available' => true]);

        $this->actingAs($owner);

        $component = Volt::test('menu.products.index');
        $owner->removeRole('owner');

        $component->call('toggleAvailability', $product->id);

        $this->assertTrue($product->fresh()->is_available);
    }

    /**
     * The whole menu.products.index page is already owner-only at
     * mount() (see ProductManagementTest's own "cashier/kitchen cannot
     * access product management" coverage) - a cashier can never reach
     * toggleAvailability() in the first place, so there is no separate
     * action-level case to exercise here beyond that existing gate.
     *
     * Toggling availability must never touch a historical order's own
     * snapshot values - those are independent columns on OrderItem,
     * frozen at order-creation time.
     */
    public function test_toggling_availability_does_not_modify_historical_order_data(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $product = $this->createProduct($restaurant, ['stock_quantity' => 10, 'price' => '7.50']);
        $order = app(\App\Services\Orders\CreateOrder::class)->handle(
            $restaurant,
            \App\Models\Customer::factory()->create(['restaurant_id' => $restaurant->id]),
            [['product_id' => $product->id, 'quantity' => 2]],
        );
        $item = $order->items->first();

        $this->actingAs($owner);
        Volt::test('menu.products.index')->call('toggleAvailability', $product->id);

        $freshItem = $item->fresh();
        $this->assertSame($item->product_name, $freshItem->product_name);
        $this->assertSame((string) $item->unit_price, (string) $freshItem->unit_price);
        $this->assertSame((string) $item->line_total, (string) $freshItem->line_total);
    }

    public function test_no_restaurant_id_or_tenant_id_property_exists_on_the_index_component(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(get_object_vars(Volt::test('menu.products.index')->instance()));

        $this->assertNotContains('restaurant_id', $publicProperties);
        $this->assertNotContains('tenant_id', $publicProperties);
    }

    // --- Product list display -------------------------------------------

    public function test_the_product_list_shows_the_available_badge(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, ['name' => 'Burger', 'is_active' => true, 'is_available' => true, 'stock_quantity' => 5]);

        $this->actingAs($owner)->get(route('menu.products.index'))->assertSee(__('Available'));
    }

    public function test_the_product_list_shows_the_unavailable_badge(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, ['name' => 'Burger', 'is_active' => true, 'is_available' => false]);

        $this->actingAs($owner)->get(route('menu.products.index'))->assertSee(__('Unavailable'));
    }

    public function test_the_product_list_shows_the_out_of_stock_badge(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, ['name' => 'Burger', 'is_active' => true, 'is_available' => true, 'stock_quantity' => 0]);

        $this->actingAs($owner)->get(route('menu.products.index'))->assertSee(__('Out of Stock'));
    }

    public function test_the_product_list_shows_the_inactive_badge_even_if_unavailable_or_out_of_stock(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, ['name' => 'Burger', 'is_active' => false, 'is_available' => false, 'stock_quantity' => 0]);

        $this->actingAs($owner)->get(route('menu.products.index'))->assertSee(__('Inactive'));
    }

    public function test_the_product_list_shows_stock_quantity_or_unlimited(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, ['name' => 'Tracked Item', 'stock_quantity' => 42]);
        $this->createProduct($restaurant, ['name' => 'Untracked Item', 'stock_quantity' => null]);

        $response = $this->actingAs($owner)->get(route('menu.products.index'));

        $response->assertSee('42');
        $response->assertSee(__('Unlimited'));
    }

    // --- Product selector exclusions -----------------------------------

    public function test_unavailable_products_do_not_appear_in_the_product_selector(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, ['name' => 'Unavailable Burger', 'is_available' => false]);

        $this->actingAs($owner);

        $names = Volt::test('orders.create')->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame([], $names);
    }

    public function test_out_of_stock_products_do_not_appear_in_the_product_selector(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, ['name' => 'Sold Out Burger', 'stock_quantity' => 0]);

        $this->actingAs($owner);

        $names = Volt::test('orders.create')->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame([], $names);
    }

    public function test_untracked_stock_products_appear_in_the_product_selector(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createProduct($restaurant, ['name' => 'Always Available Burger', 'stock_quantity' => null]);

        $this->actingAs($owner);

        $names = Volt::test('orders.create')->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame(['Always Available Burger'], $names);
    }

    public function test_in_stock_products_appear_in_the_conversation_product_selector(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = \App\Models\Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = \App\Models\Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $this->createProduct($restaurant, ['name' => 'In Stock Item', 'stock_quantity' => 3]);
        $this->createProduct($restaurant, ['name' => 'Out Of Stock Item', 'stock_quantity' => 0]);

        $this->actingAs($owner);

        $names = Volt::test('inbox.conversations.orders.create', ['conversation' => $conversation])
            ->instance()->availableProducts()->pluck('name')->all();

        $this->assertSame(['In Stock Item'], $names);
    }
}
