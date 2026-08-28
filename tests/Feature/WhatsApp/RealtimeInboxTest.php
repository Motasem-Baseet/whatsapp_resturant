<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Events\MessageCreated;
use App\Exceptions\WhatsAppMessageSendException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\SendWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 10: real-time inbox behavior built on the Phase 9 broadcasting
 * foundation (MessageCreated / MessageStatusUpdated, already tested for
 * event dispatch and channel authorization in BroadcastingTest). These
 * tests focus on what Phase 10 actually added - the Livewire components'
 * echo listener wiring and their DB-authoritative refresh behavior -
 * without a real websocket connection, by calling the listener methods
 * directly (the same thing Echo would trigger in the browser) and
 * asserting the server-rendered result.
 */
class RealtimeInboxTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(?Restaurant $restaurant = null): User
    {
        $restaurant ??= Restaurant::factory()->create();

        $owner = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $owner->assignRole(Role::findOrCreate('owner'));

        return $owner;
    }

    // --- Echo listener registration ---------------------------------------

    public function test_the_conversation_list_registers_an_echo_listener_scoped_to_the_users_own_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        Volt::test('inbox.index')
            ->assertSee("echo-private:restaurants.{$restaurant->id}.inbox,.message.created", false, false);
    }

    public function test_the_conversation_detail_page_registers_echo_listeners_scoped_to_its_own_conversations_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->assertSee("echo-private:restaurants.{$restaurant->id}.inbox,.message.created", false, false)
            ->assertSee("echo-private:restaurants.{$restaurant->id}.inbox,.message.status-updated", false, false);
    }

    // --- Conversation list real-time refresh ------------------------------

    public function test_receiving_message_created_refreshes_the_conversation_list_with_a_new_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);

        $this->actingAs($owner);

        $component = Volt::test('inbox.index');
        $component->assertDontSee('New Arrival');

        // Simulate a webhook (or outbound send) creating a brand new
        // conversation after the page already loaded.
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'New Arrival']);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id, 'last_message_at' => now()]);

        $component->call('onMessageCreated', ['id' => 1, 'conversation_id' => $conversation->id, 'direction' => 'inbound', 'content' => 'Hi', 'status' => null, 'created_at' => now()->toIso8601String()])
            ->assertSee('New Arrival');
    }

    public function test_receiving_message_created_reorders_the_conversation_list_by_last_message_at(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Older Customer']);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'name' => 'Newer Customer']);
        Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customerA->id, 'last_message_at' => now()->subHour()]);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customerB->id, 'last_message_at' => now()->subDay()]);

        $this->actingAs($owner);

        $component = Volt::test('inbox.index');
        $namesBefore = $component->instance()->conversations()->pluck('customer.name')->all();
        $this->assertSame(['Older Customer', 'Newer Customer'], $namesBefore);

        // A fresh message bumps conversation B's last_message_at past A's.
        $conversationB->update(['last_message_at' => now()]);

        $namesAfter = $component->call('onMessageCreated', ['conversation_id' => $conversationB->id])
            ->instance()->conversations()->pluck('customer.name')->all();

        $this->assertSame(['Newer Customer', 'Older Customer'], $namesAfter);
    }

    public function test_the_conversation_list_never_shows_another_restaurants_conversations_after_a_realtime_refresh(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $ownerA = $this->createOwner($restaurantA);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurantB->id, 'name' => 'Restaurant B Customer']);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurantB->id, 'customer_id' => $customerB->id]);

        $this->actingAs($ownerA);

        // Even if the listener were somehow invoked with another
        // restaurant's conversation id (it never would be in practice -
        // Echo only delivers events on channels this user authorized),
        // the underlying conversations() query stays scoped to the
        // user's own restaurant and must never surface it.
        Volt::test('inbox.index')
            ->call('onMessageCreated', ['conversation_id' => $conversationB->id])
            ->assertDontSee('Restaurant B Customer');
    }

    // --- Conversation detail real-time refresh ----------------------------

    public function test_a_new_message_for_the_open_conversation_appears_without_duplication(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);

        $this->actingAs($owner);

        $component = Volt::test('inbox.conversations.show', ['conversation' => $conversation]);
        $component->assertDontSee('Freshly arrived message');

        $message = Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'content' => 'Freshly arrived message',
        ]);

        $component->call('onMessageCreated', ['conversation_id' => $conversation->id, 'id' => $message->id])
            ->assertSeeText('Freshly arrived message', false);

        // Simulate the exact same broadcast arriving twice (Reverb/Echo
        // delivery is not guaranteed exactly-once) - messages() always
        // re-queries the database fresh, so the same row can only ever
        // render once regardless of how many times the listener fires.
        $html = $component->call('onMessageCreated', ['conversation_id' => $conversation->id, 'id' => $message->id])->html();
        $this->assertSame(1, substr_count($html, 'Freshly arrived message'));
    }

    public function test_a_message_for_a_different_conversation_does_not_appear_in_the_currently_viewed_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customerA = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $customerB = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversationA = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customerA->id]);
        $conversationB = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customerB->id]);

        // A message arrives on conversation B while the user is viewing A.
        Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversationB->id,
            'content' => 'Message for the other conversation',
        ]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversationA])
            ->call('onMessageCreated', ['conversation_id' => $conversationB->id])
            ->assertDontSee('Message for the other conversation');
    }

    public function test_a_status_update_for_the_open_conversation_updates_the_displayed_status(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $message = Message::factory()->create([
            'restaurant_id' => $restaurant->id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'status' => MessageStatus::Sent,
        ]);

        $this->actingAs($owner);

        $component = Volt::test('inbox.conversations.show', ['conversation' => $conversation]);
        $component->assertSee('sent');

        $message->update(['status' => MessageStatus::Delivered]);

        $component->call('onMessageStatusUpdated', ['conversation_id' => $conversation->id, 'id' => $message->id])
            ->assertSee('delivered')
            ->assertDontSee('sent');
    }

    public function test_a_status_update_never_creates_a_new_message_in_the_conversation(): void
    {
        $restaurant = Restaurant::factory()->create();
        $owner = $this->createOwner($restaurant);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $message = Message::factory()->create(['restaurant_id' => $restaurant->id, 'conversation_id' => $conversation->id]);

        $this->actingAs($owner);

        Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->call('onMessageStatusUpdated', ['conversation_id' => $conversation->id, 'id' => $message->id]);

        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->count());
    }

    // --- Outbound send broadcasting behavior ------------------------------

    public function test_a_successful_outbound_send_dispatches_message_created(): void
    {
        Event::fake([MessageCreated::class]);

        $restaurant = Restaurant::factory()->create();
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'phone' => '15550009999']);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $owner = $this->createOwner($restaurant);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.RT1']]], 200)]);

        app(SendWhatsAppMessage::class)->handle($account, $conversation, $owner, 'Hello');

        Event::assertDispatched(MessageCreated::class, fn ($event) => $event->message->conversation_id === $conversation->id);
    }

    public function test_a_failed_outbound_send_does_not_dispatch_message_created(): void
    {
        Event::fake([MessageCreated::class]);

        $restaurant = Restaurant::factory()->create();
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $owner = $this->createOwner($restaurant);

        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], 400)]);

        try {
            app(SendWhatsAppMessage::class)->handle($account, $conversation, $owner, 'Hello');
        } catch (WhatsAppMessageSendException) {
            // expected
        }

        Event::assertNotDispatched(MessageCreated::class);
    }

    // --- Idempotency: duplicate webhook does not double-broadcast ---------

    public function test_a_duplicate_webhook_delivery_dispatches_message_created_only_once(): void
    {
        Event::fake([MessageCreated::class]);

        $account = WhatsAppAccount::factory()->create();

        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $account->phone_number_id],
                        'contacts' => [['wa_id' => '15550001234', 'profile' => ['name' => 'Dup Customer']]],
                        'messages' => [['id' => 'wamid.DUP-RT', 'from' => '15550001234', 'type' => 'text', 'text' => ['body' => 'Hi'], 'timestamp' => (string) now()->timestamp]],
                    ],
                ]],
            ]],
        ];

        $post = fn () => $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));

        $post()->assertOk();
        $post()->assertOk();

        $this->assertSame(1, Message::where('provider_message_id', 'wamid.DUP-RT')->count());
        Event::assertDispatchedTimes(MessageCreated::class, 1);
    }
}
