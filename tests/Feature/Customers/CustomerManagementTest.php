<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Restaurant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
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

    // --- Model and relationships ---------------------------------------

    public function test_a_restaurant_can_have_many_customers(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->assertCount(2, $restaurant->customers);
        $this->assertTrue($restaurant->customers->contains($customerA));
        $this->assertTrue($restaurant->customers->contains($customerB));
    }

    public function test_a_customer_belongs_to_a_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->assertTrue($customer->restaurant->is($restaurant));
    }

    public function test_customer_queries_are_tenant_scoped(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurantA->id]);
        Customer::factory()->create(['restaurant_id' => $restaurantB->id]);

        $tenant = app(TenantContext::class);
        $tenant->set($restaurantA);

        $this->assertSame([$customerA->id], Customer::query()->pluck('id')->all());
    }

    // --- Listing / isolation --------------------------------------------

    public function test_owner_can_view_their_own_customers(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Jane Doe']);

        $response = $this->actingAs($owner)->get(route('customers.index'));

        $response->assertOk();
        $response->assertSee('Jane Doe');
    }

    public function test_restaurant_a_cannot_see_restaurant_bs_customers(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        Customer::factory()->create(['restaurant_id' => $restaurantB->id, 'name' => 'John Smith']);

        $response = $this->actingAs($ownerA)->get(route('customers.index'));

        $response->assertOk();
        $response->assertDontSee('John Smith');
    }

    // --- Creation ---------------------------------------------------------

    public function test_owner_can_create_a_customer(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner);

        Volt::test('customers.create')
            ->set('name', 'Jane Doe')
            ->set('phone', '+962790000000')
            ->set('notes', 'Prefers delivery.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('customers.index'));

        $customer = Customer::where('phone', '+962790000000')->firstOrFail();

        $this->assertSame($owner->restaurant_id, $customer->restaurant_id);
        $this->assertSame('Jane Doe', $customer->name);
    }

    public function test_the_create_customer_component_does_not_expose_a_restaurant_id_property(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('customers.create')->instance())
        );

        $this->assertNotContains('restaurant_id', $publicProperties);
    }

    public function test_the_same_phone_cannot_be_used_twice_within_the_same_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'phone' => '+962790000000']);

        $this->actingAs($owner);

        Volt::test('customers.create')
            ->set('name', 'Duplicate Phone')
            ->set('phone', '+962790000000')
            ->call('save')
            ->assertHasErrors(['phone' => 'unique']);

        $this->assertSame(1, Customer::where('restaurant_id', $restaurant->id)->where('phone', '+962790000000')->count());
    }

    public function test_the_same_phone_can_be_used_in_different_restaurants(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        Customer::factory()->create(['restaurant_id' => $restaurantB->id, 'phone' => '+962790000000']);

        $ownerA = $this->createOwner($restaurantA);
        $this->actingAs($ownerA);

        Volt::test('customers.create')
            ->set('name', 'Same Phone Different Restaurant')
            ->set('phone', '+962790000000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Customer::where('restaurant_id', $restaurantA->id)->where('phone', '+962790000000')->count());
        $this->assertSame(1, Customer::where('restaurant_id', $restaurantB->id)->where('phone', '+962790000000')->count());
    }

    public function test_phone_is_trimmed_of_surrounding_whitespace(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        Volt::test('customers.create')
            ->set('name', 'Padded Phone')
            ->set('phone', '  +962790000000  ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('customers', ['phone' => '+962790000000']);
    }

    public function test_unsafe_characters_in_phone_are_rejected(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        Volt::test('customers.create')
            ->set('name', 'Bad Phone')
            ->set('phone', '<script>alert(1)</script>')
            ->call('save')
            ->assertHasErrors(['phone']);

        $this->assertDatabaseMissing('customers', ['name' => 'Bad Phone']);
    }

    // --- Editing ------------------------------------------------------

    public function test_owner_can_edit_their_own_customer(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner);

        Volt::test('customers.edit', ['customer' => $customer])
            ->set('name', 'Updated Name')
            ->set('phone', '+962790000099')
            ->set('notes', 'Updated notes.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('customers.index'));

        $customer->refresh();

        $this->assertSame('Updated Name', $customer->name);
        $this->assertSame('+962790000099', $customer->phone);
        $this->assertSame('Updated notes.', $customer->notes);
    }

    public function test_restaurant_id_cannot_be_changed_through_editing(): void
    {
        $restaurant = Restaurant::factory()->create();
        $otherRestaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('customers.edit', ['customer' => $customer])->instance())
        );

        $this->assertNotContains('restaurant_id', $publicProperties);
        $this->assertSame($restaurant->id, $customer->fresh()->restaurant_id);
        $this->assertNotSame($otherRestaurant->id, $customer->fresh()->restaurant_id);
    }

    public function test_phone_uniqueness_during_update_ignores_the_customer_being_edited(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'phone' => '+962790000000']);

        $this->actingAs($owner);

        // Saving without changing the phone must not trip the unique
        // rule against the customer's own existing row.
        Volt::test('customers.edit', ['customer' => $customer])
            ->set('name', 'Renamed Only')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed Only', $customer->fresh()->name);
    }

    public function test_phone_uniqueness_during_update_still_rejects_another_customers_phone(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'phone' => '+962790000001']);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'phone' => '+962790000002']);

        $this->actingAs($owner);

        Volt::test('customers.edit', ['customer' => $customer])
            ->set('phone', '+962790000001')
            ->call('save')
            ->assertHasErrors(['phone' => 'unique']);

        $this->assertSame('+962790000002', $customer->fresh()->phone);
    }

    public function test_owner_cannot_edit_another_restaurants_customer(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);

        $response = $this->actingAs($ownerA)->get(route('customers.edit', $customerB));

        // Customer is tenant-scoped, so route model binding itself can't
        // find another restaurant's customer once the tenant is
        // resolved - it 404s before CustomerPolicy::update() ever runs.
        $response->assertNotFound();
    }

    // --- Search -----------------------------------------------------------

    public function test_search_by_name_returns_matching_customers(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Jane Doe']);
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'John Smith']);

        $this->actingAs($owner);

        $component = Volt::test('customers.index')->set('search', 'Jane');

        $component->assertSee('Jane Doe');
        $component->assertDontSee('John Smith');
    }

    public function test_search_by_phone_returns_matching_customers(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Jane Doe', 'phone' => '+962790000000']);
        Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'John Smith', 'phone' => '+962790001111']);

        $this->actingAs($owner);

        $component = Volt::test('customers.index')->set('search', '0000');

        $component->assertSee('Jane Doe');
        $component->assertDontSee('John Smith');
    }

    public function test_search_only_returns_customers_from_the_current_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        Customer::factory()->create(['restaurant_id' => $restaurantA->id, 'name' => 'Jane From A']);
        Customer::factory()->create(['restaurant_id' => $restaurantB->id, 'name' => 'Jane From B']);

        $this->actingAs($ownerA);

        $component = Volt::test('customers.index')->set('search', 'Jane');

        $component->assertSee('Jane From A');
        $component->assertDontSee('Jane From B');
    }

    // --- Authorization ------------------------------------------------

    /**
     * Phase 17 deliberately widened customer *viewing* (list + profile)
     * to include cashier, matching Order/Conversation's existing
     * owner-or-cashier access model - but customer creation/editing
     * remains owner-only, unchanged from Phase 5 (see CustomerPolicy).
     */
    public function test_cashier_can_view_but_not_manage_customers(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier);

        $this->get(route('customers.index'))->assertOk();
        $this->get(route('customers.create'))->assertForbidden();
    }

    public function test_kitchen_cannot_access_customer_management(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen);

        $this->get(route('customers.index'))->assertForbidden();
        $this->get(route('customers.create'))->assertForbidden();
    }
}
