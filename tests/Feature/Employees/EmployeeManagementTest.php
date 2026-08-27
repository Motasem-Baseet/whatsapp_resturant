<?php

namespace Tests\Feature\Employees;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(?Restaurant $restaurant = null): User
    {
        $restaurant ??= Restaurant::factory()->create();

        $owner = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $owner->assignRole(Role::findOrCreate('owner'));

        return $owner;
    }

    private function createEmployee(Restaurant $restaurant, string $role = 'cashier'): User
    {
        $employee = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $employee->assignRole(Role::findOrCreate($role));

        return $employee;
    }

    // --- Listing / multi-tenant isolation -----------------------------

    public function test_owner_sees_only_employees_from_their_own_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();

        $ownerA = $this->createOwner($restaurantA);
        $cashierA = $this->createEmployee($restaurantA, 'cashier');
        $kitchenB = $this->createEmployee($restaurantB, 'kitchen');

        $response = $this->actingAs($ownerA)->get(route('employees.index'));

        $response->assertOk();
        $response->assertSee($cashierA->name);
        $response->assertDontSee($kitchenB->name);
    }

    public function test_the_owner_themselves_is_not_listed_as_an_employee(): void
    {
        $owner = $this->createOwner();

        $response = $this->actingAs($owner)->get(route('employees.index'));

        $response->assertOk();

        // The owner's own email legitimately appears in the sidebar's
        // account menu - that's unrelated to the employee table. What
        // matters is that no employee *row* was rendered for the owner.
        $response->assertDontSee('employee-'.$owner->id);
    }

    // --- Creating employees ---------------------------------------------

    public function test_owner_can_create_a_cashier_employee(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner);

        Volt::test('employees.create')
            ->set('name', 'Casey Cashier')
            ->set('email', 'casey@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('role', 'cashier')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('employees.index'));

        $employee = User::where('email', 'casey@example.com')->firstOrFail();

        $this->assertSame($owner->restaurant_id, $employee->restaurant_id);
        $this->assertTrue($employee->hasRole('cashier'));
        $this->assertTrue($employee->is_active);
    }

    public function test_owner_can_create_a_kitchen_employee(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner);

        Volt::test('employees.create')
            ->set('name', 'Kit Chen')
            ->set('email', 'kit@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('role', 'kitchen')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('employees.index'));

        $employee = User::where('email', 'kit@example.com')->firstOrFail();

        $this->assertSame($owner->restaurant_id, $employee->restaurant_id);
        $this->assertTrue($employee->hasRole('kitchen'));
        $this->assertTrue($employee->is_active);
    }

    public function test_owner_cannot_create_an_employee_for_another_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);

        $this->actingAs($ownerA);

        Volt::test('employees.create')
            ->set('name', 'New Employee')
            ->set('email', 'newemp@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('role', 'cashier')
            ->call('save');

        $employee = User::where('email', 'newemp@example.com')->firstOrFail();

        $this->assertSame($restaurantA->id, $employee->restaurant_id);
        $this->assertNotSame($restaurantB->id, $employee->restaurant_id);
    }

    // --- Security ---------------------------------------------------------

    public function test_the_create_employee_component_does_not_expose_a_restaurant_id_property(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(
            get_object_vars(Volt::test('employees.create')->instance())
        );

        $this->assertNotContains('restaurant_id', $publicProperties);
    }

    public function test_owner_role_cannot_be_assigned_through_employee_creation(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        Volt::test('employees.create')
            ->set('name', 'Sneaky')
            ->set('email', 'sneaky@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('role', 'owner')
            ->call('save')
            ->assertHasErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    public function test_owner_role_cannot_be_assigned_through_employee_editing(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $employee = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($owner);

        Volt::test('employees.edit', ['employee' => $employee])
            ->set('role', 'owner')
            ->call('save')
            ->assertHasErrors(['role']);

        $this->assertTrue($employee->fresh()->hasRole('cashier'));
        $this->assertFalse($employee->fresh()->hasRole('owner'));
    }

    public function test_owner_cannot_edit_an_employee_from_another_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $employeeB = $this->createEmployee($restaurantB, 'cashier');

        $response = $this->actingAs($ownerA)->get(route('employees.edit', $employeeB));

        $response->assertForbidden();
    }

    public function test_owner_cannot_edit_another_owner_through_the_employee_screen(): void
    {
        $restaurant = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurant);
        $ownerB = $this->createOwner($restaurant);

        $response = $this->actingAs($ownerA)->get(route('employees.edit', $ownerB));

        $response->assertForbidden();
    }

    public function test_owner_cannot_edit_themselves_through_the_employee_screen(): void
    {
        $owner = $this->createOwner();

        $response = $this->actingAs($owner)->get(route('employees.edit', $owner));

        $response->assertForbidden();
    }

    public function test_cashier_cannot_access_employee_management(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier);

        $this->get(route('employees.index'))->assertForbidden();
        $this->get(route('employees.create'))->assertForbidden();
    }

    public function test_kitchen_cannot_access_employee_management(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen);

        $this->get(route('employees.index'))->assertForbidden();
        $this->get(route('employees.create'))->assertForbidden();
    }

    // --- Editing employees --------------------------------------------

    public function test_owner_can_edit_an_employee_in_their_own_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $employee = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($owner);

        Volt::test('employees.edit', ['employee' => $employee])
            ->set('name', 'Updated Name')
            ->set('role', 'kitchen')
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('employees.index'));

        $employee->refresh();

        $this->assertSame('Updated Name', $employee->name);
        $this->assertTrue($employee->hasRole('kitchen'));
        $this->assertFalse($employee->hasRole('cashier'));
        $this->assertFalse($employee->is_active);
    }

    // --- Inactive account handling --------------------------------------

    public function test_inactive_employee_cannot_log_in(): void
    {
        $restaurant = Restaurant::factory()->create();
        $employee = $this->createEmployee($restaurant, 'cashier');
        $employee->is_active = false;
        $employee->save();

        $response = Volt::test('auth.login')
            ->set('email', $employee->email)
            ->set('password', 'password')
            ->call('login');

        $response->assertHasErrors(['email']);
        $this->assertGuest();
    }

    public function test_active_employee_can_log_in_and_access_the_application(): void
    {
        $restaurant = Restaurant::factory()->create();
        $employee = $this->createEmployee($restaurant, 'cashier');

        Volt::test('auth.login')
            ->set('email', $employee->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated();

        $this->get('/dashboard')->assertOk();
    }

    public function test_an_already_authenticated_employee_is_blocked_after_being_deactivated(): void
    {
        $restaurant = Restaurant::factory()->create();
        $employee = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($employee);

        $this->get('/dashboard')->assertOk();

        $employee->is_active = false;
        $employee->save();

        $response = $this->get('/dashboard');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
