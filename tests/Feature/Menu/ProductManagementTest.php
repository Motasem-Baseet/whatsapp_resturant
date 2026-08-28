<?php

namespace Tests\Feature\Menu;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductManagementTest extends TestCase
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

    // --- Listing / isolation --------------------------------------------

    public function test_owner_can_view_their_own_products(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
        Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id, 'name' => 'Classic Burger']);

        $response = $this->actingAs($owner)->get(route('menu.products.index'));

        $response->assertOk();
        $response->assertSee('Classic Burger');
    }

    public function test_restaurant_a_cannot_see_restaurant_bs_products(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $categoryB = Category::factory()->create(['restaurant_id' => $restaurantB->id]);
        Product::factory()->create(['restaurant_id' => $restaurantB->id, 'category_id' => $categoryB->id, 'name' => 'Margherita Pizza']);

        $response = $this->actingAs($ownerA)->get(route('menu.products.index'));

        $response->assertOk();
        $response->assertDontSee('Margherita Pizza');
    }

    // --- Creation ---------------------------------------------------------

    public function test_owner_can_create_a_product(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner);

        Volt::test('menu.products.create')
            ->set('category_id', (string) $category->id)
            ->set('name', 'Classic Burger')
            ->set('description', 'A classic beef burger.')
            ->set('price', '9.99')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('menu.products.index'));

        $product = Product::where('name', 'Classic Burger')->firstOrFail();

        $this->assertSame($owner->restaurant_id, $product->restaurant_id);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('9.99', (string) $product->price);
        $this->assertTrue($product->is_active);
    }

    public function test_the_create_product_component_does_not_expose_a_restaurant_id_property(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('menu.products.create')->instance())
        );

        $this->assertNotContains('restaurant_id', $publicProperties);
    }

    public function test_owner_cannot_create_a_product_using_another_restaurants_category(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $categoryB = Category::factory()->create(['restaurant_id' => $restaurantB->id]);

        $this->actingAs($ownerA);

        Volt::test('menu.products.create')
            ->set('category_id', (string) $categoryB->id)
            ->set('name', 'Sneaky Product')
            ->set('price', '5.00')
            ->call('save')
            ->assertHasErrors(['category_id']);

        $this->assertDatabaseMissing('products', ['name' => 'Sneaky Product']);
    }

    public function test_an_inactive_category_cannot_be_selected_for_a_new_product(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $inactiveCategory = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => false]);

        $this->actingAs($owner);

        Volt::test('menu.products.create')
            ->set('category_id', (string) $inactiveCategory->id)
            ->set('name', 'Should Not Exist')
            ->set('price', '5.00')
            ->call('save')
            ->assertHasErrors(['category_id']);

        $this->assertDatabaseMissing('products', ['name' => 'Should Not Exist']);
    }

    public function test_inactive_categories_are_not_offered_in_the_create_product_dropdown(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        Category::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Active Category', 'is_active' => true]);
        Category::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Retired Category', 'is_active' => false]);

        $this->actingAs($owner);

        $response = $this->get(route('menu.products.create'));

        $response->assertSee('Active Category');
        $response->assertDontSee('Retired Category');
    }

    public function test_price_must_be_greater_than_zero(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner);

        Volt::test('menu.products.create')
            ->set('category_id', (string) $category->id)
            ->set('name', 'Free Item')
            ->set('price', '0')
            ->call('save')
            ->assertHasErrors(['price']);
    }

    // --- Editing ------------------------------------------------------

    public function test_owner_can_edit_their_own_product(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id]);

        $this->actingAs($owner);

        Volt::test('menu.products.edit', ['product' => $product])
            ->set('name', 'Updated Name')
            ->set('price', '12.50')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('menu.products.index'));

        $product->refresh();

        $this->assertSame('Updated Name', $product->name);
        $this->assertSame('12.50', (string) $product->price);
        $this->assertFalse($product->is_active);
    }

    public function test_product_can_be_reactivated(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id, 'is_active' => false]);

        $this->actingAs($owner);

        Volt::test('menu.products.edit', ['product' => $product])
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($product->refresh()->is_active);
    }

    public function test_owner_cannot_edit_another_restaurants_product(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $categoryB = Category::factory()->create(['restaurant_id' => $restaurantB->id]);
        $productB = Product::factory()->create(['restaurant_id' => $restaurantB->id, 'category_id' => $categoryB->id]);

        $response = $this->actingAs($ownerA)->get(route('menu.products.edit', $productB));

        // Product is tenant-scoped (unlike User), so route model binding
        // itself can't find another restaurant's product once the tenant
        // is resolved - it 404s before ProductPolicy::update() ever
        // runs. See the equivalent Category test for the full rationale.
        $response->assertNotFound();
    }

    public function test_owner_cannot_move_a_product_to_another_restaurants_category_via_edit(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $categoryA = Category::factory()->create(['restaurant_id' => $restaurantA->id]);
        $categoryB = Category::factory()->create(['restaurant_id' => $restaurantB->id]);
        $productA = Product::factory()->create(['restaurant_id' => $restaurantA->id, 'category_id' => $categoryA->id]);

        $this->actingAs($ownerA);

        Volt::test('menu.products.edit', ['product' => $productA])
            ->set('category_id', (string) $categoryB->id)
            ->call('save')
            ->assertHasErrors(['category_id']);

        $this->assertSame($categoryA->id, $productA->fresh()->category_id);
    }

    // --- Authorization ------------------------------------------------

    public function test_cashier_cannot_access_product_management(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier);

        $this->get(route('menu.products.index'))->assertForbidden();
        $this->get(route('menu.products.create'))->assertForbidden();
    }

    public function test_kitchen_cannot_access_product_management(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen);

        $this->get(route('menu.products.index'))->assertForbidden();
        $this->get(route('menu.products.create'))->assertForbidden();
    }
}
