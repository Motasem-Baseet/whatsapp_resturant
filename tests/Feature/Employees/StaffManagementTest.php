<?php

namespace Tests\Feature\Employees;

use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 18: extends the existing "Employees" module (list/create/edit,
 * EmployeePolicy, CreateEmployee/UpdateEmployee, EnsureUserIsActive -
 * all already built and already covered by
 * tests/Feature/Employees/EmployeeManagementTest.php, re-run unmodified
 * against this phase's changes) with search, role/status filtering,
 * pagination, and a lightweight activity summary. This file covers only
 * what Phase 18 actually added - not a re-test of what already existed.
 *
 * Owner safety was inspected rather than newly implemented: an owner
 * can never be created, edited, deactivated, or have their role changed
 * through this module at all (EmployeePolicy::manages() excludes any
 * user with the owner role; role assignment is hard-validated to
 * cashier/kitchen only in both CreateEmployee's and UpdateEmployee's
 * callers), and registration always creates exactly one owner per new
 * restaurant with no existing path to add a second one. There is no
 * reachable "last owner removed" scenario to guard against, so no new
 * guard service was added - see the existing
 * test_owner_cannot_edit_another_owner_through_the_employee_screen /
 * test_owner_cannot_edit_themselves_through_the_employee_screen /
 * test_owner_role_cannot_be_assigned_through_employee_creation /
 * test_owner_role_cannot_be_assigned_through_employee_editing tests,
 * which already prove this.
 */
class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(?Restaurant $restaurant = null): User
    {
        $restaurant ??= Restaurant::factory()->create();

        $owner = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $owner->assignRole(Role::findOrCreate('owner'));

        return $owner;
    }

    private function createEmployee(Restaurant $restaurant, string $role = 'cashier', array $attributes = []): User
    {
        $employee = User::factory()->create(array_merge(['restaurant_id' => $restaurant->id], $attributes));
        $employee->assignRole(Role::findOrCreate($role));

        return $employee;
    }

    // --- Search -----------------------------------------------------------

    public function test_search_by_name_returns_matching_employees(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createEmployee($restaurant, 'cashier', ['name' => 'Casey Cashier']);
        $this->createEmployee($restaurant, 'kitchen', ['name' => 'Kit Chen']);

        $this->actingAs($owner);

        $names = Volt::test('employees.index')
            ->set('search', 'Casey')
            ->instance()->employees()->pluck('name')->all();

        $this->assertSame(['Casey Cashier'], $names);
    }

    public function test_search_by_email_returns_matching_employees(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createEmployee($restaurant, 'cashier', ['email' => 'unique-search@example.com']);
        $this->createEmployee($restaurant, 'kitchen', ['email' => 'someone-else@example.com']);

        $this->actingAs($owner);

        $count = Volt::test('employees.index')
            ->set('search', 'unique-search')
            ->instance()->employees()->count();

        $this->assertSame(1, $count);
    }

    public function test_search_is_tenant_scoped(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $this->createEmployee($restaurantA, 'cashier', ['name' => 'Shared Name']);
        $this->createEmployee($restaurantB, 'cashier', ['name' => 'Shared Name']);

        $this->actingAs($ownerA);

        $count = Volt::test('employees.index')
            ->set('search', 'Shared Name')
            ->instance()->employees()->total();

        $this->assertSame(1, $count);
    }

    // --- Role filter ---------------------------------------------------

    public function test_role_filter_shows_only_matching_employees(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createEmployee($restaurant, 'cashier', ['name' => 'Casey Cashier']);
        $this->createEmployee($restaurant, 'kitchen', ['name' => 'Kit Chen']);

        $this->actingAs($owner);

        $names = Volt::test('employees.index')
            ->set('role', 'kitchen')
            ->instance()->employees()->pluck('name')->all();

        $this->assertSame(['Kit Chen'], $names);
    }

    public function test_an_invalid_role_filter_fails_safely_and_shows_all(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createEmployee($restaurant, 'cashier');
        $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($owner);

        $count = Volt::test('employees.index')
            ->set('role', 'owner')
            ->instance()->employees()->total();

        // "owner" is not an assignable filter value - it is treated as
        // no filter at all, never as a way to surface owner accounts
        // (which this list structurally never includes anyway).
        $this->assertSame(2, $count);
    }

    // --- Status filter -------------------------------------------------

    public function test_status_filter_shows_only_active_employees(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createEmployee($restaurant, 'cashier', ['name' => 'Active One', 'is_active' => true]);
        $this->createEmployee($restaurant, 'cashier', ['name' => 'Inactive One', 'is_active' => false]);

        $this->actingAs($owner);

        $names = Volt::test('employees.index')
            ->set('status', 'active')
            ->instance()->employees()->pluck('name')->all();

        $this->assertSame(['Active One'], $names);
    }

    public function test_status_filter_shows_only_inactive_employees(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createEmployee($restaurant, 'cashier', ['name' => 'Active One', 'is_active' => true]);
        $this->createEmployee($restaurant, 'cashier', ['name' => 'Inactive One', 'is_active' => false]);

        $this->actingAs($owner);

        $names = Volt::test('employees.index')
            ->set('status', 'inactive')
            ->instance()->employees()->pluck('name')->all();

        $this->assertSame(['Inactive One'], $names);
    }

    public function test_an_invalid_status_filter_fails_safely_and_shows_all(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createEmployee($restaurant, 'cashier', ['is_active' => true]);
        $this->createEmployee($restaurant, 'cashier', ['is_active' => false]);

        $this->actingAs($owner);

        $count = Volt::test('employees.index')
            ->set('status', 'DROP TABLE users')
            ->instance()->employees()->total();

        $this->assertSame(2, $count);
    }

    public function test_search_and_filters_combine(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->createEmployee($restaurant, 'cashier', ['name' => 'Match Me', 'is_active' => true]);
        $this->createEmployee($restaurant, 'cashier', ['name' => 'Match Me', 'is_active' => false]);
        $this->createEmployee($restaurant, 'kitchen', ['name' => 'Match Me', 'is_active' => true]);

        $this->actingAs($owner);

        $count = Volt::test('employees.index')
            ->set('search', 'Match')
            ->set('role', 'cashier')
            ->set('status', 'active')
            ->instance()->employees()->total();

        $this->assertSame(1, $count);
    }

    // --- Pagination ---------------------------------------------------

    public function test_the_employee_list_is_paginated(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        for ($i = 0; $i < 20; $i++) {
            $this->createEmployee($restaurant, 'cashier');
        }

        $this->actingAs($owner);

        $page = Volt::test('employees.index')->instance()->employees();

        $this->assertSame(15, $page->perPage());
        $this->assertSame(20, $page->total());
        $this->assertCount(15, $page->items());
    }

    // --- Activity --------------------------------------------------------

    public function test_orders_created_activity_is_correct(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $employee = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        Order::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id, 'created_by' => $employee->id]);
        Order::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id, 'created_by' => $employee->id]);

        $this->actingAs($owner);

        $activity = Volt::test('employees.edit', ['employee' => $employee])->instance()->activity();

        $this->assertSame(2, $activity['orders_created']);
    }

    public function test_conversations_assigned_activity_is_correct(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $employee = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id, 'assigned_user_id' => $employee->id]);

        $this->actingAs($owner);

        $activity = Volt::test('employees.edit', ['employee' => $employee])->instance()->activity();

        $this->assertSame(1, $activity['conversations_assigned']);
    }

    public function test_activity_never_reflects_another_restaurants_data(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $employeeA = $this->createEmployee($restaurantA, 'cashier');
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id]);
        // An order/conversation cannot actually reference a
        // different-restaurant creator/assignee due to the existing
        // composite foreign keys - this confirms the activity query
        // itself would show zero regardless, not just that such rows
        // can't exist.
        Order::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);

        $this->actingAs($ownerA);

        $activity = Volt::test('employees.edit', ['employee' => $employeeA])->instance()->activity();

        $this->assertSame(0, $activity['orders_created']);
        $this->assertSame(0, $activity['conversations_assigned']);
    }

    // --- Reactivation ----------------------------------------------------

    public function test_a_reactivated_employee_can_log_in_and_access_the_application_again(): void
    {
        $restaurant = Restaurant::factory()->create();
        $employee = $this->createEmployee($restaurant, 'cashier');
        $employee->is_active = false;
        $employee->save();

        // Confirm they are actually blocked first.
        Volt::test('auth.login')
            ->set('email', $employee->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasErrors(['email']);
        $this->assertGuest();

        $employee->is_active = true;
        $employee->save();

        Volt::test('auth.login')
            ->set('email', $employee->email)
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated();
        $this->get('/dashboard')->assertOk();
    }

    public function test_a_reactivated_employees_historical_orders_remain_intact(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $employee = $this->createEmployee($restaurant, 'cashier');
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $order = Order::factory()->create([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
            'created_by' => $employee->id,
            'status' => OrderStatus::Completed,
        ]);

        $employee->is_active = false;
        $employee->save();
        $employee->is_active = true;
        $employee->save();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'created_by' => $employee->id]);
    }

    // --- Filters expose no tenant-control state ---------------------------

    public function test_the_index_component_exposes_no_restaurant_id_property(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(get_object_vars(Volt::test('employees.index')->instance()));

        $this->assertNotContains('restaurant_id', $publicProperties);
    }
}
