<?php

namespace Tests\Feature\Menu;

use App\Models\Category;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
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

    public function test_owner_can_view_their_own_categories(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Burgers']);

        $response = $this->actingAs($owner)->get(route('menu.categories.index'));

        $response->assertOk();
        $response->assertSee('Burgers');
    }

    public function test_restaurant_a_cannot_see_restaurant_bs_categories(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        Category::factory()->create(['restaurant_id' => $restaurantB->id, 'name' => 'Pizza']);

        $response = $this->actingAs($ownerA)->get(route('menu.categories.index'));

        $response->assertOk();
        $response->assertDontSee('Pizza');
    }

    // --- Creation ---------------------------------------------------------

    public function test_owner_can_create_a_category(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner);

        Volt::test('menu.categories.create')
            ->set('name', 'Burgers')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('menu.categories.index'));

        $category = Category::where('name', 'Burgers')->firstOrFail();

        $this->assertSame($owner->restaurant_id, $category->restaurant_id);
        $this->assertTrue($category->is_active);
    }

    public function test_the_create_category_component_does_not_expose_a_restaurant_id_property(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('menu.categories.create')->instance())
        );

        $this->assertNotContains('restaurant_id', $publicProperties);
    }

    public function test_duplicate_category_name_in_the_same_restaurant_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        Category::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Burgers']);

        $this->actingAs($owner);

        Volt::test('menu.categories.create')
            ->set('name', 'Burgers')
            ->call('save')
            ->assertHasErrors(['name' => 'unique']);

        $this->assertSame(1, Category::where('restaurant_id', $restaurant->id)->where('name', 'Burgers')->count());
    }

    public function test_the_same_category_name_is_allowed_in_different_restaurants(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        Category::factory()->create(['restaurant_id' => $restaurantB->id, 'name' => 'Burgers']);

        $ownerA = $this->createOwner($restaurantA);
        $this->actingAs($ownerA);

        Volt::test('menu.categories.create')
            ->set('name', 'Burgers')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Category::where('restaurant_id', $restaurantA->id)->where('name', 'Burgers')->count());
        $this->assertSame(1, Category::where('restaurant_id', $restaurantB->id)->where('name', 'Burgers')->count());
    }

    // --- Editing ------------------------------------------------------

    public function test_owner_can_edit_their_own_category(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Burgers']);

        $this->actingAs($owner);

        Volt::test('menu.categories.edit', ['category' => $category])
            ->set('name', 'Beef Burgers')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('menu.categories.index'));

        $category->refresh();

        $this->assertSame('Beef Burgers', $category->name);
        $this->assertFalse($category->is_active);
    }

    public function test_category_can_be_reactivated(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => false]);

        $this->actingAs($owner);

        Volt::test('menu.categories.edit', ['category' => $category])
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($category->refresh()->is_active);
    }

    public function test_owner_cannot_edit_another_restaurants_category(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $categoryB = Category::factory()->create(['restaurant_id' => $restaurantB->id]);

        $response = $this->actingAs($ownerA)->get(route('menu.categories.edit', $categoryB));

        // Unlike User (which has no tenant global scope), Category is
        // tenant-scoped, so route model binding itself can't find
        // another restaurant's category once the tenant is resolved -
        // it 404s before CategoryPolicy::update() ever runs. That is a
        // stronger form of isolation than a 403 (it doesn't even
        // confirm the record exists), so it's the expected outcome here.
        $response->assertNotFound();
    }

    // --- Authorization ------------------------------------------------

    public function test_cashier_cannot_access_category_management(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier);

        $this->get(route('menu.categories.index'))->assertForbidden();
        $this->get(route('menu.categories.create'))->assertForbidden();
    }

    public function test_kitchen_cannot_access_category_management(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen);

        $this->get(route('menu.categories.index'))->assertForbidden();
        $this->get(route('menu.categories.create'))->assertForbidden();
    }

    // --- Deactivation safety --------------------------------------------

    public function test_deactivating_a_category_does_not_touch_its_products(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
        $product = \App\Models\Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $this->actingAs($owner);

        Volt::test('menu.categories.edit', ['category' => $category])
            ->set('is_active', false)
            ->call('save');

        $this->assertNotNull($product->fresh());
        $this->assertTrue($product->fresh()->is_active);
    }
}
