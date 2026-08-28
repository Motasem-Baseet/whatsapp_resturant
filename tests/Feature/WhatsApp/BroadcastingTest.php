<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\MessageDirection;
use App\Events\MessageCreated;
use App\Events\MessageStatusUpdated;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Inbox\CreateMessage;
use App\Services\WhatsApp\UpdateMessageDeliveryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastingTest extends TestCase
{
    use RefreshDatabase;

    private function makeConversation(Restaurant $restaurant): Conversation
    {
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);

        return Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
    }

    /**
     * routes/channels.php's Broadcast::channel() calls register patterns
     * only on whichever driver was the default at the moment the file
     * was required during app boot (the 'null' driver - see
     * phpunit.xml). Merely switching config('broadcasting.default')
     * afterward resolves a fresh 'reverb' driver instance with no
     * patterns registered on it at all, which would silently deny every
     * request rather than exercising the real authorization logic - so
     * the channel definitions are re-registered here, onto the
     * now-current default.
     */
    private function useReverbBroadcaster(): void
    {
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
    }

    // --- Channel authorization --------------------------------------------

    /**
     * The auth endpoint's real channel-authorization logic only runs
     * under a "real" broadcaster (pusher/reverb) - the null/log drivers
     * used elsewhere in this suite (see phpunit.xml) no-op auth()
     * entirely, which would make these tests pass regardless of who is
     * asking. Switching to 'reverb' here is still purely local HMAC
     * signing (see PusherBroadcaster::validAuthenticationResponse) - no
     * network call is made, so this is safe without a running server.
     */
    public function test_a_user_from_the_owning_restaurant_can_authorize_the_inbox_channel(): void
    {
        $this->useReverbBroadcaster();
        $restaurant = Restaurant::factory()->create();
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);

        $response = $this->actingAs($user)->post('/broadcasting/auth', [
            'channel_name' => "private-restaurants.{$restaurant->id}.inbox",
            'socket_id' => '1234.5678',
        ]);

        $response->assertOk();
    }

    public function test_a_user_from_a_different_restaurant_cannot_authorize_the_inbox_channel(): void
    {
        $this->useReverbBroadcaster();
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $userB = User::factory()->create(['restaurant_id' => $restaurantB->id]);

        $response = $this->actingAs($userB)->post('/broadcasting/auth', [
            'channel_name' => "private-restaurants.{$restaurantA->id}.inbox",
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }

    public function test_an_unauthenticated_request_cannot_authorize_the_inbox_channel(): void
    {
        $this->useReverbBroadcaster();
        $restaurant = Restaurant::factory()->create();

        $response = $this->post('/broadcasting/auth', [
            'channel_name' => "private-restaurants.{$restaurant->id}.inbox",
            'socket_id' => '1234.5678',
        ]);

        $response->assertForbidden();
    }

    // --- Event dispatching -------------------------------------------

    public function test_message_created_is_dispatched_on_a_restaurant_scoped_channel_for_an_incoming_webhook_message(): void
    {
        Event::fake([MessageCreated::class]);

        $account = WhatsAppAccount::factory()->create();

        $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $account->phone_number_id],
                        'contacts' => [['wa_id' => '15550001111', 'profile' => ['name' => 'Alice']]],
                        'messages' => [['id' => 'wamid.EVT1', 'from' => '15550001111', 'type' => 'text', 'text' => ['body' => 'Hi'], 'timestamp' => (string) now()->timestamp]],
                    ],
                ]],
            ]],
        ]))->assertOk();

        Event::assertDispatched(MessageCreated::class, function ($event) use ($account) {
            return $event->message->restaurant_id === $account->restaurant_id
                && $event->message->direction === MessageDirection::Inbound;
        });
    }

    public function test_message_created_broadcasts_on_the_correct_private_channel(): void
    {
        $restaurant = Restaurant::factory()->create();
        $conversation = $this->makeConversation($restaurant);

        $message = app(CreateMessage::class)->handle($conversation, [
            'direction' => MessageDirection::Outbound,
            'content' => 'Broadcast me',
        ]);

        $event = new MessageCreated($message);
        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        // PrivateChannel::name is stored with its "private-" prefix
        // already applied (see Illuminate\Broadcasting\PrivateChannel).
        $this->assertSame("private-restaurants.{$restaurant->id}.inbox", $channels[0]->name);
        $this->assertSame('message.created', $event->broadcastAs());
        $this->assertArrayNotHasKey('access_token', $event->broadcastWith());
    }

    public function test_message_status_updated_is_dispatched_when_a_status_webhook_arrives(): void
    {
        Event::fake([MessageStatusUpdated::class]);

        $account = WhatsAppAccount::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $account->restaurant_id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $account->restaurant_id, 'customer_id' => $customer->id]);
        $message = \App\Models\Message::factory()->create([
            'restaurant_id' => $account->restaurant_id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'provider_message_id' => 'wamid.STATUS1',
        ]);

        app(UpdateMessageDeliveryStatus::class)->handle($account->restaurant, [
            'provider_message_id' => 'wamid.STATUS1',
            'status' => 'delivered',
            'timestamp' => null,
        ]);

        Event::assertDispatched(MessageStatusUpdated::class, fn ($event) => $event->message->id === $message->id);
    }
}
