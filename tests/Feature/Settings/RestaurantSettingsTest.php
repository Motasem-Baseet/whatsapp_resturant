<?php

namespace Tests\Feature\Settings;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RestaurantSettingsTest extends TestCase
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

    // --- Access -------------------------------------------------------

    public function test_owner_can_access_restaurant_settings(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)->get(route('settings.restaurant'))->assertOk();
    }

    public function test_cashier_receives_403(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier)->get(route('settings.restaurant'))->assertForbidden();
    }

    public function test_kitchen_receives_403(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen)->get(route('settings.restaurant'))->assertForbidden();
    }

    public function test_a_roleless_user_receives_403(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($user)->get(route('settings.restaurant'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.restaurant'))->assertRedirect(route('login'));
    }

    // --- Rendering ------------------------------------------------------

    public function test_current_restaurant_data_is_displayed(): void
    {
        // Flux's <flux:input wire:model="..."> does not render a plain
        // value="" attribute into the raw SSR HTML (Livewire/Flux
        // hydrate it client-side instead), so assertSee() against an
        // input's bound value isn't a reliable signal here - the
        // component's own hydrated state is what actually reaches the
        // browser and is what genuinely proves mount() loaded the right
        // restaurant.
        $restaurant = Restaurant::factory()->create([
            'name' => 'Tasty Bites',
            'phone' => '555-0100',
            'address' => '123 Main St',
        ]);
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        $component = Volt::test('settings.restaurant');

        $this->assertSame('Tasty Bites', $component->get('name'));
        $this->assertSame('555-0100', $component->get('phone'));
        $this->assertSame('123 Main St', $component->get('address'));
    }

    public function test_restaurant_a_never_displays_restaurant_bs_data(): void
    {
        $restaurantA = Restaurant::factory()->create(['name' => 'Restaurant A']);
        $restaurantB = Restaurant::factory()->create(['name' => 'Restaurant B Only']);
        $ownerA = $this->createOwner($restaurantA);

        $this->actingAs($ownerA);

        $component = Volt::test('settings.restaurant');

        $this->assertSame('Restaurant A', $component->get('name'));
        $this->assertNotSame('Restaurant B Only', $component->get('name'));
    }

    public function test_the_settings_navigation_shows_the_restaurant_link_only_for_owner(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($owner);
        Volt::test('settings.profile')->assertSee(route('settings.restaurant'), false);

        $this->actingAs($cashier);
        Volt::test('settings.profile')->assertDontSee(route('settings.restaurant'), false);
    }

    // --- Updates -----------------------------------------------------

    public function test_owner_can_update_restaurant_fields(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('settings.restaurant')
            ->set('name', 'New Name')
            ->set('phone', '555-9999')
            ->set('address', '456 New Ave')
            ->set('logo_path', 'https://example.com/logo.png')
            ->call('save')
            ->assertHasNoErrors();

        $restaurant->refresh();
        $this->assertSame('New Name', $restaurant->name);
        $this->assertSame('555-9999', $restaurant->phone);
        $this->assertSame('456 New Ave', $restaurant->address);
        $this->assertSame('https://example.com/logo.png', $restaurant->logo_path);
    }

    public function test_invalid_fields_are_rejected(): void
    {
        $restaurant = Restaurant::factory()->create(['name' => 'Original Name']);
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('settings.restaurant')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);

        $this->assertSame('Original Name', $restaurant->fresh()->name);
    }

    public function test_an_invalid_logo_url_is_rejected(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('settings.restaurant')
            ->set('logo_path', 'not-a-url')
            ->call('save')
            ->assertHasErrors(['logo_path' => 'url']);
    }

    public function test_a_blank_logo_url_is_allowed(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('settings.restaurant')
            ->set('logo_path', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($restaurant->fresh()->logo_path);
    }

    public function test_the_component_state_refreshes_with_the_saved_values(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        $component = Volt::test('settings.restaurant')
            ->set('name', 'Refreshed Name')
            ->call('save');

        $this->assertSame('Refreshed Name', $component->get('name'));
    }

    // --- Tenant isolation ------------------------------------------------

    public function test_restaurant_a_owner_cannot_modify_restaurant_b(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create(['name' => 'Restaurant B Original']);
        $ownerA = $this->createOwner($restaurantA);

        $this->actingAs($ownerA);

        Volt::test('settings.restaurant')
            ->set('name', 'Hijacked Name')
            ->call('save');

        $this->assertSame('Restaurant B Original', $restaurantB->fresh()->name);
    }

    public function test_saves_only_ever_affect_the_authenticated_users_own_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $originalBName = $restaurantB->name;
        $originalBPhone = $restaurantB->phone;
        $originalBAddress = $restaurantB->address;

        $this->actingAs($ownerA);

        Volt::test('settings.restaurant')
            ->set('name', 'Only A Changes')
            ->call('save');

        $this->assertSame('Only A Changes', $restaurantA->fresh()->name);
        $this->assertSame($originalBName, $restaurantB->fresh()->name);
        $this->assertSame($originalBPhone, $restaurantB->fresh()->phone);
        $this->assertSame($originalBAddress, $restaurantB->fresh()->address);
    }

    public function test_no_public_restaurant_id_property_exists(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(get_object_vars(Volt::test('settings.restaurant')->instance()));

        $this->assertNotContains('restaurant_id', $publicProperties);
        $this->assertNotContains('restaurant', $publicProperties);
    }

    // --- Authorization (action-time, not just mount) -----------------

    public function test_a_role_revoked_after_mount_still_blocks_the_save_action(): void
    {
        // Proves save() re-checks authorization itself rather than
        // trusting mount() having already passed - Livewire actions do
        // not re-run mount() on a subsequent request, so a user whose
        // role was revoked mid-session (e.g. demoted by another owner)
        // must still be rejected when they try to save.
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        $component = Volt::test('settings.restaurant');

        $owner->removeRole('owner');

        // Volt::test()->call() catches the abort(403) internally
        // (rendering an error response rather than letting it surface
        // as a raw PHP exception here) - the meaningful assertion is
        // the actual security property: the restaurant was not
        // updated.
        $component->set('name', 'Should Not Save')->call('save');

        $this->assertNotSame('Should Not Save', $restaurant->fresh()->name);
    }
}
