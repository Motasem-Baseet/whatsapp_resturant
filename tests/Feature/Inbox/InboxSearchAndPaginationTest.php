<?php

namespace Tests\Feature\Inbox;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 25: server-side customer name/phone search and pagination for
 * the inbox list - the filter dimensions (assignment/status/read) and
 * everything else on this page already existed and are covered by
 * tests/Feature/WhatsApp/InboxFiltersAndUnreadCountTest.php; these tests
 * only cover the two genuinely new pieces added in this phase, plus
 * their interaction with the existing filters.
 */
class InboxSearchAndPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(?Restaurant $restaurant = null): User
    {
        $restaurant ??= Restaurant::factory()->create();

        $owner = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $owner->assignRole(Role::findOrCreate('owner'));

        return $owner;
    }

    private function conversationFor(Restaurant $restaurant, string $customerName, string $phone, array $attributes = []): Conversation
    {
        $customer = Customer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'name' => $customerName,
            'phone' => $phone,
        ]);

        return Conversation::factory()->create(array_merge([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
        ], $attributes));
    }

    // --- Search -----------------------------------------------------------

    public function test_search_matches_by_customer_name(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->conversationFor($restaurant, 'Alice Johnson', '15550001111');
        $this->conversationFor($restaurant, 'Bob Smith', '15550002222');

        $this->actingAs($owner);

        $names = Volt::test('inbox.index')
            ->set('search', 'Alice')
            ->instance()->conversations()->pluck('customer.name')->all();

        $this->assertSame(['Alice Johnson'], $names);
    }

    public function test_search_matches_by_customer_phone(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->conversationFor($restaurant, 'Alice Johnson', '15550001111');
        $this->conversationFor($restaurant, 'Bob Smith', '15550002222');

        $this->actingAs($owner);

        $names = Volt::test('inbox.index')
            ->set('search', '2222')
            ->instance()->conversations()->pluck('customer.name')->all();

        $this->assertSame(['Bob Smith'], $names);
    }

    public function test_a_search_with_no_matches_returns_an_empty_list_without_error(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->conversationFor($restaurant, 'Alice Johnson', '15550001111');

        $this->actingAs($owner);

        Volt::test('inbox.index')
            ->set('search', 'Nobody Matches This')
            ->assertOk()
            ->assertSee(__('No conversations match these filters.'));
    }

    public function test_search_is_tenant_scoped(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $this->conversationFor($restaurantB, 'Restaurant B Customer', '15559998888');

        $this->actingAs($ownerA);

        $names = Volt::test('inbox.index')
            ->set('search', 'Restaurant B')
            ->instance()->conversations()->pluck('customer.name')->all();

        $this->assertSame([], $names);
    }

    public function test_search_combines_correctly_with_the_status_filter(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->conversationFor($restaurant, 'Alice Open', '15550001111', ['status' => ConversationStatus::Open]);
        $this->conversationFor($restaurant, 'Alice Closed', '15550002222', ['status' => ConversationStatus::Closed]);

        $this->actingAs($owner);

        $names = Volt::test('inbox.index')
            ->set('search', 'Alice')
            ->set('statusFilter', 'open')
            ->instance()->conversations()->pluck('customer.name')->all();

        $this->assertSame(['Alice Open'], $names);
    }

    public function test_search_combines_correctly_with_the_assignment_filter(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->conversationFor($restaurant, 'Alice Mine', '15550001111', ['assigned_user_id' => $owner->id]);
        $this->conversationFor($restaurant, 'Alice Unassigned', '15550002222', ['assigned_user_id' => null]);

        $this->actingAs($owner);

        $names = Volt::test('inbox.index')
            ->set('search', 'Alice')
            ->set('assignmentFilter', 'unassigned')
            ->instance()->conversations()->pluck('customer.name')->all();

        $this->assertSame(['Alice Unassigned'], $names);
    }

    // --- Pagination ---------------------------------------------------------

    public function test_the_inbox_paginates_at_fifteen_conversations_per_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        for ($i = 1; $i <= 20; $i++) {
            $this->conversationFor($restaurant, "Customer {$i}", "1555000{$i}");
        }

        $this->actingAs($owner);

        $paginator = Volt::test('inbox.index')->instance()->conversations();

        $this->assertCount(15, $paginator->items());
        $this->assertSame(20, $paginator->total());
    }

    public function test_changing_the_search_term_resets_pagination_to_the_first_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        for ($i = 1; $i <= 20; $i++) {
            $this->conversationFor($restaurant, "Customer {$i}", "1555000{$i}");
        }

        $this->actingAs($owner);

        $component = Volt::test('inbox.index');
        $component->call('gotoPage', 2);
        $this->assertSame(2, $component->instance()->conversations()->currentPage());

        $component->set('search', 'Customer 1');
        $this->assertSame(1, $component->instance()->conversations()->currentPage());
    }

    public function test_changing_a_filter_resets_pagination_to_the_first_page(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        for ($i = 1; $i <= 20; $i++) {
            $this->conversationFor($restaurant, "Customer {$i}", "1555000{$i}");
        }

        $this->actingAs($owner);

        $component = Volt::test('inbox.index');
        $component->call('gotoPage', 2);
        $this->assertSame(2, $component->instance()->conversations()->currentPage());

        $component->set('statusFilter', 'open');
        $this->assertSame(1, $component->instance()->conversations()->currentPage());
    }

    // --- Security -----------------------------------------------------------

    public function test_the_component_exposes_no_client_controlled_tenant_property(): void
    {
        $owner = $this->createOwner();
        $this->actingAs($owner);

        $publicProperties = array_keys(get_object_vars(Volt::test('inbox.index')->instance()));

        $this->assertNotContains('restaurant_id', $publicProperties);
        $this->assertNotContains('tenant_id', $publicProperties);
    }
}
