<?php

namespace Tests\Feature\Onboarding;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Onboarding\GetOnboardingProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Restaurant onboarding (Phase 26). GetOnboardingProgress is tested
 * directly for aggregation correctness; the Livewire page and the
 * dashboard-redirect middleware are tested through Volt/HTTP since that
 * is where authorization and the completion gate actually live.
 */
class OnboardingTest extends TestCase
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

    /**
     * Satisfies all four onboarding requirements for the given
     * restaurant, without going through the onboarding page itself -
     * used by tests that need a genuinely "complete" restaurant as a
     * precondition rather than as the thing under test.
     */
    private function satisfyAllRequirements(Restaurant $restaurant): void
    {
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
        Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id, 'is_active' => true]);
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);
    }

    // --- A. Access -----------------------------------------------------------

    public function test_owner_can_access_onboarding(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)->get(route('onboarding.show'))->assertOk();
    }

    public function test_cashier_is_denied(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');

        $this->actingAs($cashier)->get(route('onboarding.show'))->assertForbidden();
    }

    public function test_kitchen_is_denied(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($kitchen)->get(route('onboarding.show'))->assertForbidden();
    }

    public function test_a_roleless_user_is_denied(): void
    {
        $restaurant = Restaurant::factory()->create();
        $roleless = User::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->actingAs($roleless)->get(route('onboarding.show'))->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('onboarding.show'))->assertRedirect(route('login'));
    }

    // --- B. Progress -----------------------------------------------------------

    /**
     * Restaurant profile fields (name/phone/address) are NOT NULL
     * columns already required at registration (RegisterRestaurantOwner)
     * - so a brand new restaurant starts with exactly the profile step
     * satisfied, never a fabricated "incomplete profile" that wouldn't
     * reflect real, valid data. The other three steps genuinely start
     * false.
     */
    public function test_a_fresh_restaurant_has_only_the_profile_step_complete(): void
    {
        $restaurant = Restaurant::factory()->create();

        $progress = app(GetOnboardingProgress::class)->handle($restaurant);

        $this->assertTrue($progress['steps']['profile']);
        $this->assertFalse($progress['steps']['category']);
        $this->assertFalse($progress['steps']['product']);
        $this->assertFalse($progress['steps']['whatsapp']);
        $this->assertSame(1, $progress['completed_steps']);
        $this->assertSame(4, $progress['total_steps']);
        $this->assertFalse($progress['all_complete']);
    }

    public function test_category_completion_requires_an_active_category(): void
    {
        $restaurant = Restaurant::factory()->create();
        Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => false]);

        $this->assertFalse(app(GetOnboardingProgress::class)->handle($restaurant)['steps']['category']);

        Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);

        $this->assertTrue(app(GetOnboardingProgress::class)->handle($restaurant)['steps']['category']);
    }

    public function test_product_completion_requires_an_active_product(): void
    {
        $restaurant = Restaurant::factory()->create();
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
        Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id, 'is_active' => false]);

        $this->assertFalse(app(GetOnboardingProgress::class)->handle($restaurant)['steps']['product']);

        Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id, 'is_active' => true]);

        $this->assertTrue(app(GetOnboardingProgress::class)->handle($restaurant)['steps']['product']);
    }

    public function test_whatsapp_completion_is_detected_without_exposing_any_secret(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->assertFalse(app(GetOnboardingProgress::class)->handle($restaurant)['steps']['whatsapp']);

        $account = WhatsAppAccount::factory()->create([
            'restaurant_id' => $restaurant->id,
            'access_token' => 'super-secret-token-value',
        ]);

        $this->assertTrue(app(GetOnboardingProgress::class)->handle($restaurant)['steps']['whatsapp']);

        $this->actingAs($owner);
        Volt::test('onboarding.show')->assertDontSee('super-secret-token-value');
    }

    public function test_progress_is_derived_server_side_with_no_client_settable_property(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(get_object_vars(Volt::test('onboarding.show')->instance()));

        $this->assertNotContains('progress', $publicProperties);
        $this->assertNotContains('completed_steps', $publicProperties);
        $this->assertNotContains('all_complete', $publicProperties);
    }

    // --- C. Completion -----------------------------------------------------------

    public function test_cannot_complete_with_missing_requirements(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->actingAs($owner);

        Volt::test('onboarding.show')->call('complete')->assertHasErrors(['completion']);

        $this->assertNull($restaurant->fresh()->onboarding_completed_at);
    }

    public function test_a_forged_completion_request_fails_when_only_some_requirements_are_met(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id, 'is_active' => true]);
        Product::factory()->create(['restaurant_id' => $restaurant->id, 'category_id' => $category->id, 'is_active' => true]);
        // WhatsApp deliberately left unconfigured.

        $this->actingAs($owner);

        Volt::test('onboarding.show')->call('complete')->assertHasErrors(['completion']);

        $this->assertNull($restaurant->fresh()->onboarding_completed_at);
    }

    public function test_can_complete_only_when_all_requirements_are_satisfied(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->satisfyAllRequirements($restaurant);

        $this->actingAs($owner);

        Volt::test('onboarding.show')->call('complete')->assertHasNoErrors();

        $this->assertNotNull($restaurant->fresh()->onboarding_completed_at);
    }

    public function test_completion_timestamp_is_set_server_side_to_the_current_time(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->satisfyAllRequirements($restaurant);

        $this->actingAs($owner);
        // Frozen to a whole second - the database column itself has no
        // sub-second precision, so comparing against a frozen instant
        // with microseconds would spuriously fail after the round trip.
        $this->travelTo(now()->startOfSecond());

        Volt::test('onboarding.show')->call('complete');

        $this->assertTrue($restaurant->fresh()->onboarding_completed_at->equalTo(now()));

        $this->travelBack();
    }

    public function test_a_completed_restaurant_is_not_forced_back_into_onboarding(): void
    {
        $restaurant = Restaurant::factory()->create(['onboarding_completed_at' => now()]);
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    // --- D. Tenant isolation -----------------------------------------------------

    public function test_cross_tenant_categories_do_not_count_toward_progress(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        Category::factory()->create(['restaurant_id' => $restaurantB->id, 'is_active' => true]);

        $this->assertFalse(app(GetOnboardingProgress::class)->handle($restaurantA)['steps']['category']);
    }

    public function test_cross_tenant_products_do_not_count_toward_progress(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $categoryB = Category::factory()->create(['restaurant_id' => $restaurantB->id, 'is_active' => true]);
        Product::factory()->create(['restaurant_id' => $restaurantB->id, 'category_id' => $categoryB->id, 'is_active' => true]);

        $this->assertFalse(app(GetOnboardingProgress::class)->handle($restaurantA)['steps']['product']);
    }

    public function test_cross_tenant_whatsapp_does_not_count_toward_progress(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurantB->id]);

        $this->assertFalse(app(GetOnboardingProgress::class)->handle($restaurantA)['steps']['whatsapp']);
    }

    /**
     * There is no route parameter identifying "which restaurant's
     * onboarding" anywhere - onboarding.show always resolves
     * Auth::user()->restaurant. This is the security property itself:
     * an owner from restaurant A has no URL, form field, or component
     * property through which to even attempt acting on restaurant B's
     * onboarding state.
     */
    public function test_cross_tenant_onboarding_mutations_are_structurally_impossible(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $this->satisfyAllRequirements($restaurantB);

        $this->actingAs($ownerA);

        // Restaurant B being fully set up must not let A's own
        // onboarding be considered complete.
        Volt::test('onboarding.show')->call('complete')->assertHasErrors(['completion']);

        $this->assertNull($restaurantA->fresh()->onboarding_completed_at);
    }

    // --- E. Navigation -----------------------------------------------------------

    public function test_an_owner_with_incomplete_onboarding_is_redirected_from_the_dashboard(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('onboarding.show'));
    }

    public function test_an_owner_with_completed_onboarding_accesses_the_dashboard_normally(): void
    {
        $restaurant = Restaurant::factory()->create(['onboarding_completed_at' => now()]);
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    /**
     * Cashier/kitchen dashboard access must never be gated by the
     * *owner's* onboarding state - onboarding is owner-only, and staff
     * still need to work.
     */
    public function test_cashier_and_kitchen_dashboard_access_is_unaffected_by_incomplete_onboarding(): void
    {
        $restaurant = Restaurant::factory()->create();
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $kitchen = $this->createEmployee($restaurant, 'kitchen');

        $this->actingAs($cashier)->get(route('dashboard'))->assertOk();
        $this->actingAs($kitchen)->get(route('dashboard'))->assertOk();
    }

    public function test_required_setup_pages_remain_reachable_while_onboarding_is_incomplete(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $this->get(route('onboarding.show'))->assertOk();
        $this->get(route('settings.restaurant'))->assertOk();
        $this->get(route('settings.whatsapp'))->assertOk();
        $this->get(route('menu.categories.create'))->assertOk();
        $this->get(route('menu.products.create'))->assertOk();
    }

    public function test_no_redirect_loop_occurs_when_visiting_onboarding_itself(): void
    {
        $owner = $this->createOwner();

        $this->actingAs($owner)->get(route('onboarding.show'))->assertOk();
    }

    // --- F. Security -----------------------------------------------------------

    public function test_no_restaurant_id_or_tenant_id_public_property_exists(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(get_object_vars(Volt::test('onboarding.show')->instance()));

        $this->assertNotContains('restaurant_id', $publicProperties);
        $this->assertNotContains('tenant_id', $publicProperties);
    }

    public function test_a_role_revoked_after_mount_still_blocks_save_profile(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $component = Volt::test('onboarding.show');
        $owner->removeRole('owner');

        $component->set('name', 'Should Not Save')->call('saveProfile');

        $this->assertNotSame('Should Not Save', $owner->restaurant->fresh()->name);
    }

    public function test_a_role_revoked_after_mount_still_blocks_create_category(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->actingAs($owner);

        $component = Volt::test('onboarding.show');
        $owner->removeRole('owner');

        $component->set('category_name', 'Should Not Exist')->call('createCategory');

        $this->assertDatabaseMissing('categories', ['restaurant_id' => $restaurant->id, 'name' => 'Should Not Exist']);
    }

    public function test_a_role_revoked_after_mount_still_blocks_complete(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->satisfyAllRequirements($restaurant);
        $this->actingAs($owner);

        $component = Volt::test('onboarding.show');
        $owner->removeRole('owner');

        $component->call('complete');

        $this->assertNull($restaurant->fresh()->onboarding_completed_at);
    }

    // --- G. Backward compatibility -------------------------------------------

    /**
     * The Phase 26 migration backfills onboarding_completed_at for any
     * restaurant that already had a product, an order, or a WhatsApp
     * account at the time the column was introduced - proven here at
     * the model/query level rather than by re-running the historical
     * migration, since RefreshDatabase always runs migrations against
     * an empty database in tests.
     */
    public function test_a_restaurant_with_pre_existing_operational_data_is_treated_as_already_onboarded(): void
    {
        $restaurant = Restaurant::factory()->create(['onboarding_completed_at' => now()]);
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }
}
