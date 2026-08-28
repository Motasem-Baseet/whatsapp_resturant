<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationUserRead;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Inbox\MarkConversationAsRead;
use App\Services\WhatsApp\SendWhatsAppMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReadStateTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(?Restaurant $restaurant = null): User
    {
        $restaurant ??= Restaurant::factory()->create();

        $owner = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $owner->assignRole(Role::findOrCreate('owner'));

        return $owner;
    }

    private function createCashier(Restaurant $restaurant): User
    {
        $cashier = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $cashier->assignRole(Role::findOrCreate('cashier'));

        return $cashier;
    }

    private function conversationWithMessage(Restaurant $restaurant): Conversation
    {
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
        ]);

        return $conversation;
    }

    // --- MarkConversationAsRead service -----------------------------------

    public function test_marking_a_conversation_as_read_creates_a_read_state_row(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        app(MarkConversationAsRead::class)->handle($conversation, $owner);

        $this->assertDatabaseHas('conversation_user_reads', [
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'restaurant_id' => $restaurant->id,
        ]);
    }

    public function test_marking_a_conversation_as_read_twice_is_idempotent(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        app(MarkConversationAsRead::class)->handle($conversation, $owner);
        app(MarkConversationAsRead::class)->handle($conversation, $owner);

        $this->assertSame(1, ConversationUserRead::where('conversation_id', $conversation->id)->where('user_id', $owner->id)->count());
    }

    public function test_one_users_read_state_does_not_affect_another_users_read_state(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createCashier($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        app(MarkConversationAsRead::class)->handle($conversation, $owner);

        $this->assertDatabaseHas('conversation_user_reads', ['conversation_id' => $conversation->id, 'user_id' => $owner->id]);
        $this->assertDatabaseMissing('conversation_user_reads', ['conversation_id' => $conversation->id, 'user_id' => $cashier->id]);
    }

    public function test_the_service_rejects_a_user_from_a_different_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $userB = $this->createOwner($restaurantB);
        $conversationA = $this->conversationWithMessage($restaurantA);

        $this->expectException(InvalidArgumentException::class);

        app(MarkConversationAsRead::class)->handle($conversationA, $userB);
    }

    // --- Database integrity -------------------------------------------

    public function test_the_database_rejects_a_duplicate_read_state_row_for_the_same_user_and_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        ConversationUserRead::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
        ]);

        $this->expectException(QueryException::class);

        DB::table('conversation_user_reads')->insert([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'last_read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_a_read_state_row_whose_restaurant_does_not_match_its_conversations_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $conversationA = $this->conversationWithMessage($restaurantA);

        $this->expectException(QueryException::class);

        DB::table('conversation_user_reads')->insert([
            'restaurant_id' => $restaurantB->id,
            'conversation_id' => $conversationA->id,
            'user_id' => $ownerA->id,
            'last_read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_rejects_a_read_state_row_whose_restaurant_does_not_match_its_users_restaurant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $userB = $this->createOwner($restaurantB);
        $conversationA = $this->conversationWithMessage($restaurantA);

        $this->expectException(QueryException::class);

        DB::table('conversation_user_reads')->insert([
            'restaurant_id' => $restaurantA->id,
            'conversation_id' => $conversationA->id,
            'user_id' => $userB->id,
            'last_read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_allows_a_correctly_matched_read_state_row(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        DB::table('conversation_user_reads')->insert([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'last_read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('conversation_user_reads', ['conversation_id' => $conversation->id, 'user_id' => $owner->id]);
    }

    // --- Unread determination -------------------------------------------

    public function test_a_never_read_conversation_with_messages_is_unread(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        $unreadIds = Conversation::query()->unreadFor($owner)->pluck('id')->all();

        $this->assertContains($conversation->id, $unreadIds);
    }

    public function test_a_conversation_with_no_messages_is_not_unread(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $unreadIds = Conversation::query()->unreadFor($owner)->pluck('id')->all();

        $this->assertNotContains($conversation->id, $unreadIds);
    }

    public function test_a_conversation_read_after_its_last_message_is_not_unread(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        app(MarkConversationAsRead::class)->handle($conversation, $owner);

        $unreadIds = Conversation::query()->unreadFor($owner)->pluck('id')->all();

        $this->assertNotContains($conversation->id, $unreadIds);
    }

    public function test_a_new_message_after_reading_makes_the_conversation_unread_again(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        app(MarkConversationAsRead::class)->handle($conversation, $owner);
        $this->assertNotContains($conversation->id, Conversation::query()->unreadFor($owner)->pluck('id')->all());

        // The read/unread comparison is second-precision (matching the
        // existing messages.created_at column, unmodified from Phase 6) -
        // advance the clock a full second so this message is
        // unambiguously "after" the read, exactly as any real message
        // arriving even slightly later than a read would be.
        $this->travel(1)->second();

        Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
        ]);

        $this->assertContains($conversation->id, Conversation::query()->unreadFor($owner)->pluck('id')->all());
    }

    public function test_unread_state_is_independent_per_user(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createCashier($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        app(MarkConversationAsRead::class)->handle($conversation, $owner);

        $this->assertNotContains($conversation->id, Conversation::query()->unreadFor($owner)->pluck('id')->all());
        $this->assertContains($conversation->id, Conversation::query()->unreadFor($cashier)->pluck('id')->all());
    }

    // --- Opening a conversation marks it read -----------------------------

    public function test_opening_a_conversation_marks_it_read_for_the_viewing_user_only(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createCashier($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        $this->actingAs($owner);
        Volt::test('inbox.conversations.show', ['conversation' => $conversation]);

        $this->assertDatabaseHas('conversation_user_reads', ['conversation_id' => $conversation->id, 'user_id' => $owner->id]);
        $this->assertDatabaseMissing('conversation_user_reads', ['conversation_id' => $conversation->id, 'user_id' => $cashier->id]);
    }

    public function test_revisiting_an_already_read_conversation_does_not_duplicate_the_read_row(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $conversation = $this->conversationWithMessage($restaurant);

        $this->actingAs($owner);
        Volt::test('inbox.conversations.show', ['conversation' => $conversation]);
        Volt::test('inbox.conversations.show', ['conversation' => $conversation]);

        $this->assertSame(1, ConversationUserRead::where('conversation_id', $conversation->id)->where('user_id', $owner->id)->count());
    }

    // --- Sender does not receive unread state for their own message -------

    public function test_the_sender_of_an_outbound_message_does_not_get_unread_state_for_their_own_message(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.SELFREAD']]], 200)]);

        app(SendWhatsAppMessage::class)->handle($account, $conversation, $owner, 'Hello');

        $this->assertNotContains($conversation->id, Conversation::query()->unreadFor($owner)->pluck('id')->all());
    }

    public function test_another_user_still_sees_unread_state_after_someone_elses_outbound_send(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createCashier($restaurant);
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OTHERSEND']]], 200)]);

        app(SendWhatsAppMessage::class)->handle($account, $conversation, $owner, 'Hello');

        $this->assertContains($conversation->id, Conversation::query()->unreadFor($cashier)->pluck('id')->all());
    }

    // --- Active viewer receives read state on a live incoming message -----

    public function test_a_message_arriving_while_the_conversation_is_open_marks_it_read_for_the_active_viewer(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);
        $component = Volt::test('inbox.conversations.show', ['conversation' => $conversation]);

        $message = Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
        ]);

        $component->call('onMessageCreated', ['conversation_id' => $conversation->id, 'id' => $message->id]);

        $this->assertNotContains($conversation->id, Conversation::query()->unreadFor($owner)->pluck('id')->all());
    }

    public function test_a_message_arriving_for_the_open_conversation_does_not_mark_it_read_for_other_users(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $cashier = $this->createCashier($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);
        $component = Volt::test('inbox.conversations.show', ['conversation' => $conversation]);

        $message = Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
        ]);

        $component->call('onMessageCreated', ['conversation_id' => $conversation->id, 'id' => $message->id]);

        $this->assertContains($conversation->id, Conversation::query()->unreadFor($cashier)->pluck('id')->all());
    }
}
