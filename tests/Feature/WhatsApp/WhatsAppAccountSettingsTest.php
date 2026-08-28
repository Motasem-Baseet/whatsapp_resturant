<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WhatsAppAccountSettingsTest extends TestCase
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

    public function test_owner_can_access_whatsapp_settings(): void
    {
        // Volt::test() rather than a raw HTTP GET: the latter renders
        // through x-settings.layout via a real request/view-finder
        // pass, which hits a pre-existing, unrelated "No hint path
        // defined for [layouts]" bug shared by every settings page
        // (confirmed independently against the untouched
        // settings/profile route, and already tracked by the known
        // ProfileUpdateTest::test_profile_page_is_displayed failure).
        // Volt::test() exercises the same component/authorization logic
        // without going through that broken pathway.
        $owner = $this->createOwner();

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')->assertOk();
    }

    public function test_cashier_receives_403(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier)->get(route('settings.whatsapp'))->assertForbidden();
    }

    public function test_kitchen_receives_403(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen)->get(route('settings.whatsapp'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('settings.whatsapp'))->assertRedirect(route('login'));
    }

    // --- Creation -------------------------------------------------------

    public function test_owner_can_configure_a_new_account(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('phone_number_id', 'phone-123')
            ->set('business_account_id', 'biz-456')
            ->set('display_phone_number', '+15550001111')
            ->set('access_token', 'brand-new-token')
            ->set('is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_accounts', [
            'restaurant_id' => $restaurant->id,
            'phone_number_id' => 'phone-123',
            'business_account_id' => 'biz-456',
        ]);
    }

    public function test_the_created_account_belongs_to_the_authenticated_users_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('phone_number_id', 'phone-owner-restaurant')
            ->set('access_token', 'a-token')
            ->call('save');

        $account = WhatsAppAccount::withoutGlobalScopes()->where('phone_number_id', 'phone-owner-restaurant')->firstOrFail();

        $this->assertSame($restaurant->id, $account->restaurant_id);
    }

    public function test_there_is_no_client_controlled_restaurant_id_input(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(get_object_vars(Volt::test('settings.whatsapp')->instance()));

        $this->assertNotContains('restaurant_id', $publicProperties);
    }

    public function test_a_new_account_requires_an_access_token(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('phone_number_id', 'phone-needs-token')
            ->set('access_token', '')
            ->call('save')
            ->assertHasErrors(['access_token']);

        $this->assertDatabaseMissing('whatsapp_accounts', ['phone_number_id' => 'phone-needs-token']);
    }

    public function test_an_account_can_be_created_as_inactive(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('phone_number_id', 'phone-inactive')
            ->set('access_token', 'a-token')
            ->set('is_active', false)
            ->call('save');

        $this->assertDatabaseHas('whatsapp_accounts', ['phone_number_id' => 'phone-inactive', 'is_active' => false]);
    }

    // --- Editing ----------------------------------------------------

    public function test_owner_can_update_public_fields_of_an_existing_account(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        WhatsAppAccount::factory()->create([
            'restaurant_id' => $restaurant->id,
            'phone_number_id' => 'existing-phone',
            'display_phone_number' => '+10000000000',
        ]);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('display_phone_number', '+19999999999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_accounts', [
            'restaurant_id' => $restaurant->id,
            'display_phone_number' => '+19999999999',
        ]);
    }

    public function test_owner_can_replace_the_access_token(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);
        $account->access_token = 'old-access-token';
        $account->save();

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('access_token', 'new-access-token')
            ->call('save');

        $this->assertSame('new-access-token', $account->fresh()->access_token);
    }

    public function test_owner_can_replace_the_verify_token(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id, 'verify_token' => 'old-verify-token']);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('verify_token', 'new-verify-token')
            ->call('save');

        $this->assertSame('new-verify-token', $account->fresh()->verify_token);
    }

    public function test_owner_can_replace_the_app_secret(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id, 'app_secret' => 'old-app-secret']);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('app_secret', 'new-app-secret')
            ->call('save');

        $this->assertSame('new-app-secret', $account->fresh()->app_secret);
    }

    public function test_a_blank_access_token_preserves_the_existing_token(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);
        $account->access_token = 'keep-this-token';
        $account->save();

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('display_phone_number', '+12223334444')
            ->set('access_token', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('keep-this-token', $account->fresh()->access_token);
    }

    public function test_a_blank_verify_token_preserves_the_existing_verify_token(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id, 'verify_token' => 'keep-this-verify-token']);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('display_phone_number', '+12223334444')
            ->set('verify_token', '')
            ->call('save');

        $this->assertSame('keep-this-verify-token', $account->fresh()->verify_token);
    }

    public function test_a_blank_app_secret_preserves_the_existing_app_secret(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id, 'app_secret' => 'keep-this-app-secret']);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('display_phone_number', '+12223334444')
            ->set('app_secret', '')
            ->call('save');

        $this->assertSame('keep-this-app-secret', $account->fresh()->app_secret);
    }

    // --- Secret security ---------------------------------------------

    public function test_existing_secrets_are_never_rendered_in_the_page_html(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->create([
            'restaurant_id' => $restaurant->id,
            'verify_token' => 'render-check-verify-token',
            'app_secret' => 'render-check-app-secret',
        ]);
        $account->access_token = 'render-check-access-token';
        $account->save();

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->assertDontSee('render-check-verify-token')
            ->assertDontSee('render-check-app-secret')
            ->assertDontSee('render-check-access-token');
    }

    public function test_existing_secrets_are_not_loaded_into_public_component_state(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->create([
            'restaurant_id' => $restaurant->id,
            'verify_token' => 'state-check-verify-token',
            'app_secret' => 'state-check-app-secret',
        ]);
        $account->access_token = 'state-check-access-token';
        $account->save();

        $this->actingAs($owner);

        $instance = Volt::test('settings.whatsapp')->instance();

        $this->assertSame('', $instance->access_token);
        $this->assertSame('', $instance->verify_token);
        $this->assertSame('', $instance->app_secret);
    }

    public function test_secrets_remain_hidden_from_model_serialization_after_being_saved_through_the_ui(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('phone_number_id', 'serialization-check')
            ->set('access_token', 'serialization-secret')
            ->call('save');

        $account = WhatsAppAccount::withoutGlobalScopes()->where('phone_number_id', 'serialization-check')->firstOrFail();
        $array = $account->toArray();

        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('app_secret', $array);
        $this->assertArrayNotHasKey('verify_token', $array);
    }

    public function test_the_access_token_remains_encrypted_at_rest_after_being_saved_through_the_ui(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('phone_number_id', 'encryption-check')
            ->set('access_token', 'plaintext-should-not-appear')
            ->call('save');

        $raw = DB::table('whatsapp_accounts')->where('phone_number_id', 'encryption-check')->value('access_token');

        $this->assertStringNotContainsString('plaintext-should-not-appear', $raw);
    }

    // --- Tenant isolation ----------------------------------------------

    public function test_an_owner_with_no_account_does_not_see_another_restaurants_configuration(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurantB->id, 'display_phone_number' => '+1restaurantB']);

        $this->actingAs($ownerA);

        $instance = Volt::test('settings.whatsapp')->instance();

        $this->assertFalse($instance->has_account);
        $this->assertSame('', $instance->display_phone_number);
    }

    public function test_saving_as_owner_a_never_touches_restaurant_bs_account(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $accountB = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurantB->id, 'display_phone_number' => '+1restaurantB']);

        $this->actingAs($ownerA);

        Volt::test('settings.whatsapp')
            ->set('phone_number_id', 'phone-for-a')
            ->set('access_token', 'token-for-a')
            ->call('save');

        $this->assertSame('+1restaurantB', $accountB->fresh()->display_phone_number);
        $this->assertDatabaseHas('whatsapp_accounts', ['restaurant_id' => $restaurantA->id, 'phone_number_id' => 'phone-for-a']);
    }

    // --- Phone number uniqueness ---------------------------------------

    public function test_a_duplicate_global_phone_number_id_is_rejected(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerB = $this->createOwner($restaurantB);
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurantA->id, 'phone_number_id' => 'shared-phone-id']);

        $this->actingAs($ownerB);

        Volt::test('settings.whatsapp')
            ->set('phone_number_id', 'shared-phone-id')
            ->set('access_token', 'token-b')
            ->call('save')
            ->assertHasErrors(['phone_number_id']);

        $this->assertDatabaseMissing('whatsapp_accounts', ['restaurant_id' => $restaurantB->id]);
    }

    public function test_an_account_can_keep_its_own_phone_number_id_when_edited(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id, 'phone_number_id' => 'my-own-phone-id']);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('phone_number_id', 'my-own-phone-id')
            ->set('display_phone_number', '+1changed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_accounts', ['phone_number_id' => 'my-own-phone-id', 'display_phone_number' => '+1changed']);
    }

    public function test_a_different_unique_phone_number_id_succeeds_on_edit(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id, 'phone_number_id' => 'original-phone-id']);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('phone_number_id', 'brand-new-unique-id')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('whatsapp_accounts', ['restaurant_id' => $restaurant->id, 'phone_number_id' => 'brand-new-unique-id']);
    }

    // --- Active state ----------------------------------------------------

    public function test_owner_can_activate_the_account(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->inactive()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('is_active', true)
            ->call('save');

        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_owner_can_deactivate_the_account(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);

        $this->actingAs($owner);

        Volt::test('settings.whatsapp')
            ->set('is_active', false)
            ->call('save');

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_activation_change_affects_only_the_current_restaurants_account(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurantA->id, 'is_active' => true]);
        $accountB = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurantB->id, 'is_active' => true]);

        $this->actingAs($ownerA);

        Volt::test('settings.whatsapp')
            ->set('is_active', false)
            ->call('save');

        $this->assertTrue($accountB->fresh()->is_active);
    }

    // --- Command compatibility -------------------------------------------

    public function test_the_console_command_still_works_after_the_shared_service_refactor(): void
    {
        $restaurant = Restaurant::factory()->create();

        $this->artisan('whatsapp:configure', [
            'restaurant' => $restaurant->id,
            'phone_number_id' => 'cli-phone-id',
            'access_token' => 'cli-access-token',
            '--business-account-id' => 'cli-biz-id',
        ])->assertExitCode(0);

        $account = WhatsAppAccount::withoutGlobalScopes()->where('phone_number_id', 'cli-phone-id')->firstOrFail();

        $this->assertSame($restaurant->id, $account->restaurant_id);
        $this->assertSame('cli-access-token', $account->access_token);
        $this->assertSame('cli-biz-id', $account->business_account_id);
        $this->assertNotEmpty($account->verify_token);
        $this->assertTrue($account->is_active);
    }
}
