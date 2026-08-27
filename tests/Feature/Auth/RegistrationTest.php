<?php

namespace Tests\Feature\Auth;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\Auth\RegisterRestaurantOwner;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register_as_a_restaurant_owner(): void
    {
        $response = Volt::test('auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('restaurant_name', 'Test Diner')
            ->set('restaurant_phone', '555-0100')
            ->set('restaurant_address', '1 Test Street')
            ->call('register');

        $response
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();

        $user = User::where('email', 'test@example.com')->firstOrFail();
        $restaurant = Restaurant::where('name', 'Test Diner')->firstOrFail();

        $this->assertSame($restaurant->id, $user->restaurant_id);
        $this->assertSame('555-0100', $restaurant->phone);
        $this->assertSame('1 Test Street', $restaurant->address);
        $this->assertTrue($user->hasRole('owner'));
    }

    public function test_tenant_resolves_to_the_new_restaurant_immediately_after_registration(): void
    {
        Volt::test('auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('restaurant_name', 'Test Diner')
            ->set('restaurant_phone', '555-0100')
            ->set('restaurant_address', '1 Test Street')
            ->call('register');

        $restaurant = Restaurant::where('name', 'Test Diner')->firstOrFail();

        // A subsequent request, going through the normal IdentifyTenant
        // middleware, must resolve the tenant from the authenticated
        // user - not from anything set during registration itself.
        $this->get('/dashboard')->assertStatus(200);

        $this->assertSame($restaurant->id, app(TenantContext::class)->id());
    }

    public function test_restaurant_information_is_required_to_register(): void
    {
        $response = Volt::test('auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('restaurant_name', '')
            ->set('restaurant_phone', '')
            ->set('restaurant_address', '')
            ->call('register');

        $response->assertHasErrors([
            'restaurant_name' => 'required',
            'restaurant_phone' => 'required',
            'restaurant_address' => 'required',
        ]);

        $this->assertGuest();
        $this->assertDatabaseCount('restaurants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_overlong_restaurant_information_is_rejected(): void
    {
        $response = Volt::test('auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('restaurant_name', str_repeat('a', 256))
            ->set('restaurant_phone', str_repeat('1', 31))
            ->set('restaurant_address', str_repeat('a', 256))
            ->call('register');

        $response->assertHasErrors([
            'restaurant_name' => 'max',
            'restaurant_phone' => 'max',
            'restaurant_address' => 'max',
        ]);

        $this->assertDatabaseCount('restaurants', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_registration_component_does_not_expose_restaurant_id_or_role_as_settable_properties(): void
    {
        // The client can only ever influence the public properties the
        // component declares. restaurant_id and role are never among
        // them, so there is no wire:model payload that could set them.
        $publicProperties = array_keys(
            get_object_vars(Volt::test('auth.register')->instance())
        );

        $this->assertNotContains('restaurant_id', $publicProperties);
        $this->assertNotContains('role', $publicProperties);
        $this->assertNotContains('permission', $publicProperties);
    }

    public function test_registration_always_creates_a_brand_new_restaurant_regardless_of_existing_ones(): void
    {
        // Even with another restaurant already in the database, a new
        // registration must always create - and attach the user to -
        // its own new restaurant, never an existing one.
        $existing = Restaurant::factory()->create();

        Volt::test('auth.register')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('restaurant_name', 'Test Diner')
            ->set('restaurant_phone', '555-0100')
            ->set('restaurant_address', '1 Test Street')
            ->call('register');

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertNotSame($existing->id, $user->restaurant_id);
    }

    public function test_registration_is_atomic_and_leaves_no_orphan_restaurant_on_failure(): void
    {
        $action = app(RegisterRestaurantOwner::class);

        $action->handle(
            owner: ['name' => 'First Owner', 'email' => 'owner@example.com', 'password' => 'password'],
            restaurant: ['name' => 'First Diner', 'phone' => '111', 'address' => '1 First Street'],
        );

        $this->assertDatabaseCount('restaurants', 1);
        $this->assertDatabaseCount('users', 1);

        // A duplicate email slipping through to the action (e.g. a race
        // condition past Livewire's own validation) must fail the whole
        // transaction, not just the user insert.
        try {
            $action->handle(
                owner: ['name' => 'Second Owner', 'email' => 'owner@example.com', 'password' => 'password'],
                restaurant: ['name' => 'Second Diner', 'phone' => '222', 'address' => '2 Second Street'],
            );

            $this->fail('Expected a duplicate email to raise a database exception.');
        } catch (QueryException $e) {
            // Expected: unique constraint violation on users.email.
        }

        $this->assertDatabaseCount('restaurants', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('roles', 1);
    }
}
