<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Inbox\MarkConversationAsRead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InboxFiltersAndUnreadCountTest extends TestCase
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

    private function conversationWithMessage(Restaurant $restaurant, array $attributes = []): Conversation
    {
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => $attributes['customer_name'] ?? 'Customer']);
        unset($attributes['customer_name']);

        $conversation = Conversation::factory()->create(array_merge([
            'restaurant_id' => $restaurant->id,
            'customer_id' => $customer->id,
        ], $attributes));

        Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
        ]);

        return $conversation;
    }

    // --- Assignment filters -------------------------------------------

    public function test_all_filter_shows_every_conversation_in_the_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->conversationWithMessage($restaurant, ['customer_name' => 'Customer One']);
        $this->conversationWithMessage($restaurant, ['customer_name' => 'Customer Two']);

        $this->actingAs($owner);

        $names = Volt::test('inbox.index')->instance()->conversations()->pluck('customer.name')->all();

        $this->assertCount(2, $names);
    }

    public function test_mine_filter_shows_only_conversations_assigned_to_the_current_user(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $other = $this->createOwner($restaurant);
        $mine = $this->conversationWithMessage($restaurant, ['assigned_user_id' => $owner->id, 'customer_name' => 'Mine']);
        $this->conversationWithMessage($restaurant, ['assigned_user_id' => $other->id, 'customer_name' => 'Theirs']);

        $this->actingAs($owner);

        $ids = Volt::test('inbox.index')
            ->set('assignmentFilter', 'mine')
            ->instance()->conversations()->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
    }

    public function test_unassigned_filter_shows_only_conversations_with_no_assignee(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $unassigned = $this->conversationWithMessage($restaurant, ['assigned_user_id' => null, 'customer_name' => 'Unassigned']);
        $this->conversationWithMessage($restaurant, ['assigned_user_id' => $owner->id, 'customer_name' => 'Assigned']);

        $this->actingAs($owner);

        $ids = Volt::test('inbox.index')
            ->set('assignmentFilter', 'unassigned')
            ->instance()->conversations()->pluck('id')->all();

        $this->assertSame([$unassigned->id], $ids);
    }

    public function test_assigned_filter_shows_only_conversations_with_an_assignee(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->conversationWithMessage($restaurant, ['assigned_user_id' => null, 'customer_name' => 'Unassigned']);
        $assigned = $this->conversationWithMessage($restaurant, ['assigned_user_id' => $owner->id, 'customer_name' => 'Assigned']);

        $this->actingAs($owner);

        $ids = Volt::test('inbox.index')
            ->set('assignmentFilter', 'assigned')
            ->instance()->conversations()->pluck('id')->all();

        $this->assertSame([$assigned->id], $ids);
    }

    // --- Read filter ----------------------------------------------------

    public function test_unread_filter_shows_only_conversations_unread_for_the_current_user(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $unread = $this->conversationWithMessage($restaurant, ['customer_name' => 'Unread']);
        $read = $this->conversationWithMessage($restaurant, ['customer_name' => 'Read']);
        app(MarkConversationAsRead::class)->handle($read, $owner);

        $this->actingAs($owner);

        $ids = Volt::test('inbox.index')
            ->set('readFilter', 'unread')
            ->instance()->conversations()->pluck('id')->all();

        $this->assertSame([$unread->id], $ids);
    }

    // --- Status filters ---------------------------------------------------

    public function test_open_filter_shows_only_open_conversations(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $open = $this->conversationWithMessage($restaurant, ['status' => ConversationStatus::Open, 'customer_name' => 'Open']);
        $this->conversationWithMessage($restaurant, ['status' => ConversationStatus::Closed, 'customer_name' => 'Closed']);

        $this->actingAs($owner);

        $ids = Volt::test('inbox.index')
            ->set('statusFilter', 'open')
            ->instance()->conversations()->pluck('id')->all();

        $this->assertSame([$open->id], $ids);
    }

    public function test_closed_filter_shows_only_closed_conversations(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->conversationWithMessage($restaurant, ['status' => ConversationStatus::Open, 'customer_name' => 'Open']);
        $closed = $this->conversationWithMessage($restaurant, ['status' => ConversationStatus::Closed, 'customer_name' => 'Closed']);

        $this->actingAs($owner);

        $ids = Volt::test('inbox.index')
            ->set('statusFilter', 'closed')
            ->instance()->conversations()->pluck('id')->all();

        $this->assertSame([$closed->id], $ids);
    }

    // --- Invalid / malicious filter input ---------------------------------

    public function test_an_invalid_assignment_filter_value_falls_back_to_all_rather_than_erroring(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->conversationWithMessage($restaurant);

        $this->actingAs($owner);

        $ids = Volt::test('inbox.index')
            ->set('assignmentFilter', 'DROP TABLE conversations')
            ->instance()->conversations()->pluck('id')->all();

        $this->assertCount(1, $ids);
    }

    public function test_an_invalid_status_filter_value_falls_back_to_all(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $this->conversationWithMessage($restaurant, ['status' => ConversationStatus::Closed]);

        $this->actingAs($owner);

        $ids = Volt::test('inbox.index')
            ->set('statusFilter', 'not-a-real-status')
            ->instance()->conversations()->pluck('id')->all();

        $this->assertCount(1, $ids);
    }

    // --- Filters remain tenant-scoped --------------------------------

    public function test_filters_never_expose_another_restaurants_conversations(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $this->conversationWithMessage($restaurantB, ['customer_name' => 'Restaurant B']);

        $this->actingAs($ownerA);

        foreach (['all', 'mine', 'unassigned', 'assigned'] as $assignment) {
            $names = Volt::test('inbox.index')
                ->set('assignmentFilter', $assignment)
                ->instance()->conversations()->pluck('customer.name')->all();

            $this->assertNotContains('Restaurant B', $names);
        }
    }

    // --- Unread count (sidebar badge) -------------------------------------

    public function test_unread_count_is_per_authenticated_user(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createEmployee($restaurant, 'cashier');
        $conversation = $this->conversationWithMessage($restaurant);
        app(MarkConversationAsRead::class)->handle($conversation, $owner);
        // Second, still-unread conversation for the cashier only.
        $this->conversationWithMessage($restaurant);

        $this->actingAs($owner);
        $ownerCount = Volt::test('inbox.unread-badge')->instance()->unreadCount();

        $this->actingAs($cashier);
        $cashierCount = Volt::test('inbox.unread-badge')->instance()->unreadCount();

        $this->assertSame(1, $ownerCount);
        $this->assertSame(2, $cashierCount);
    }

    public function test_unread_count_is_restaurant_scoped(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $this->conversationWithMessage($restaurantB);

        $this->actingAs($ownerA);

        $count = Volt::test('inbox.unread-badge')->instance()->unreadCount();

        $this->assertSame(0, $count);
    }

    public function test_unread_count_updates_after_reading_a_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        $this->actingAs($owner);
        $this->assertSame(1, Volt::test('inbox.unread-badge')->instance()->unreadCount());

        app(MarkConversationAsRead::class)->handle($conversation, $owner);

        $this->assertSame(0, Volt::test('inbox.unread-badge')->instance()->unreadCount());
    }

    public function test_unread_count_updates_after_a_new_message_arrives(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);
        app(MarkConversationAsRead::class)->handle($conversation, $owner);

        $this->actingAs($owner);
        $this->assertSame(0, Volt::test('inbox.unread-badge')->instance()->unreadCount());

        // See ReadStateTest for why this advances the clock a full
        // second - the unread comparison is second-precision, matching
        // the existing (unmodified) messages.created_at column.
        $this->travel(1)->second();

        Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
        ]);

        $this->assertSame(1, Volt::test('inbox.unread-badge')->instance()->unreadCount());
    }

    public function test_kitchen_user_sees_zero_unread_count_even_if_directly_queried(): void
    {
        $restaurant = Restaurant::factory()->create();
        $kitchen = $this->createEmployee($restaurant, 'kitchen');
        $this->conversationWithMessage($restaurant);

        $this->actingAs($kitchen);

        // Kitchen never sees the component in the UI (hidden behind the
        // same @can as the rest of the inbox), but the component itself
        // is asserted here to independently refuse to leak a count.
        $count = Volt::test('inbox.unread-badge')->instance()->unreadCount();

        $this->assertSame(0, $count);
    }

    // --- Real-time refresh of unread count via echo listener --------------

    public function test_the_badge_component_registers_an_echo_listener_scoped_to_the_users_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('inbox.unread-badge')
            ->assertSee("echo-private:restaurants.{$restaurant->id}.inbox,.message.created", false, false);
    }
}
