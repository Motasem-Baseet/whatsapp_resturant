<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToRestaurant;
use App\Models\Restaurant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestaurantTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ad-hoc table for a test-only tenant-owned model, so the
        // BelongsToRestaurant foundation can be exercised without
        // creating a permanent business model. Created inside the
        // per-test transaction that RefreshDatabase opens, so it is
        // rolled back automatically after each test.
        Schema::create('tenancy_test_records', function ($table) {
            $table->id();
            $table->unsignedBigInteger('restaurant_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_a_restaurant_can_have_multiple_users(): void
    {
        $restaurant = Restaurant::factory()->create();

        $userA = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $userB = User::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->assertCount(2, $restaurant->users);
        $this->assertTrue($restaurant->users->contains($userA));
        $this->assertTrue($restaurant->users->contains($userB));
    }

    public function test_a_user_belongs_to_a_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->assertTrue($user->restaurant->is($restaurant));
    }

    public function test_creating_a_tenant_owned_record_assigns_the_current_restaurant_id(): void
    {
        $restaurant = Restaurant::factory()->create();

        app(TenantContext::class)->set($restaurant);

        $record = TenancyTestRecord::create(['name' => 'Widget']);

        $this->assertSame($restaurant->id, $record->restaurant_id);
    }

    public function test_creating_a_record_without_a_current_tenant_does_not_assign_a_restaurant_id(): void
    {
        $record = TenancyTestRecord::create(['name' => 'Unowned Widget']);

        $this->assertNull($record->restaurant_id);
    }

    public function test_tenant_scoped_queries_only_return_records_for_the_current_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $tenant = app(TenantContext::class);

        $tenant->set($restaurantA);
        TenancyTestRecord::create(['name' => 'A record']);

        $tenant->set($restaurantB);
        TenancyTestRecord::create(['name' => 'B record']);

        $tenant->set($restaurantA);
        $this->assertSame(['A record'], TenancyTestRecord::pluck('name')->all());

        $tenant->set($restaurantB);
        $this->assertSame(['B record'], TenancyTestRecord::pluck('name')->all());
    }

    public function test_tenant_a_cannot_retrieve_tenant_b_records_through_a_scoped_query(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $tenant = app(TenantContext::class);

        $tenant->set($restaurantB);
        $recordB = TenancyTestRecord::create(['name' => 'B record']);

        $tenant->set($restaurantA);

        $this->assertNull(TenancyTestRecord::find($recordB->id));
    }

    public function test_no_tenant_scope_is_applied_when_there_is_no_current_tenant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $tenant = app(TenantContext::class);

        $tenant->set($restaurantA);
        TenancyTestRecord::create(['name' => 'A record']);

        $tenant->set($restaurantB);
        TenancyTestRecord::create(['name' => 'B record']);

        $tenant->clear();

        $this->assertFalse($tenant->check());
        $this->assertCount(2, TenancyTestRecord::all());
    }

    public function test_tenant_context_can_be_explicitly_set_and_cleared(): void
    {
        $restaurant = Restaurant::factory()->create();
        $tenant = app(TenantContext::class);

        $this->assertFalse($tenant->check());
        $this->assertNull($tenant->id());

        $tenant->set($restaurant);
        $this->assertTrue($tenant->check());
        $this->assertSame($restaurant->id, $tenant->id());

        $tenant->clear();
        $this->assertFalse($tenant->check());
        $this->assertNull($tenant->id());
    }

    public function test_tenant_is_resolved_from_the_authenticated_user_and_cannot_be_spoofed_via_request_input(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurantA->id]);

        $this->actingAs($user)
            ->get('/dashboard?restaurant_id='.$restaurantB->id)
            ->assertStatus(200);

        $this->assertSame($restaurantA->id, app(TenantContext::class)->id());
    }

    public function test_guest_requests_do_not_set_a_current_tenant(): void
    {
        $this->get('/')->assertStatus(200);

        $this->assertFalse(app(TenantContext::class)->check());
    }
}

/**
 * Minimal, test-only model used to exercise the BelongsToRestaurant
 * foundation without creating a permanent business model.
 */
class TenancyTestRecord extends Model
{
    use BelongsToRestaurant;

    protected $table = 'tenancy_test_records';

    protected $guarded = [];
}
