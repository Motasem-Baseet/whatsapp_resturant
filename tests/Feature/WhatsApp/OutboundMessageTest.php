<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Exceptions\WhatsAppMessageSendException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\SendWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OutboundMessageTest extends TestCase
{
    use RefreshDatabase;

    private function makeConversation(): array
    {
        $restaurant = Restaurant::factory()->create();
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id, 'phone' => '15559998888']);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $user->assignRole(Role::findOrCreate('owner'));

        return compact('restaurant', 'account', 'customer', 'conversation', 'user');
    }

    public function test_sending_a_message_calls_the_whatsapp_api_with_the_recipient_and_body(): void
    {
        ['account' => $account, 'conversation' => $conversation, 'user' => $user] = $this->makeConversation();

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.SENT1']]], 200)]);

        app(SendWhatsAppMessage::class)->handle($account, $conversation, $user, 'Your order is ready!');

        Http::assertSent(function ($request) use ($account) {
            return str_contains($request->url(), $account->phone_number_id)
                && $request['to'] === '15559998888'
                && $request['text']['body'] === 'Your order is ready!';
        });
    }

    public function test_sending_a_message_creates_an_outbound_message_with_the_provider_id(): void
    {
        ['account' => $account, 'conversation' => $conversation, 'user' => $user] = $this->makeConversation();

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.SENT2']]], 200)]);

        $message = app(SendWhatsAppMessage::class)->handle($account, $conversation, $user, 'Your order is ready!');

        $this->assertSame(MessageDirection::Outbound, $message->direction);
        $this->assertSame('wamid.SENT2', $message->provider_message_id);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame($conversation->restaurant_id, $message->restaurant_id);
        $this->assertSame($conversation->id, $message->conversation_id);
    }

    public function test_sending_a_message_updates_conversation_last_message_at(): void
    {
        ['account' => $account, 'conversation' => $conversation, 'user' => $user] = $this->makeConversation();
        $conversation->update(['last_message_at' => null]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.SENT3']]], 200)]);

        app(SendWhatsAppMessage::class)->handle($account, $conversation, $user, 'Hi there');

        $this->assertNotNull($conversation->fresh()->last_message_at);
    }

    public function test_a_failed_provider_response_throws_and_does_not_create_a_message(): void
    {
        ['account' => $account, 'conversation' => $conversation, 'user' => $user] = $this->makeConversation();

        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid phone number']], 400)]);

        $this->expectException(WhatsAppMessageSendException::class);

        try {
            app(SendWhatsAppMessage::class)->handle($account, $conversation, $user, 'Hi there');
        } finally {
            $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id]);
        }
    }

    public function test_an_account_from_another_restaurant_cannot_send_into_this_conversation(): void
    {
        ['conversation' => $conversation, 'user' => $user] = $this->makeConversation();
        $otherAccount = WhatsAppAccount::factory()->create();

        $this->expectException(WhatsAppMessageSendException::class);

        app(SendWhatsAppMessage::class)->handle($otherAccount, $conversation, $user, 'Hi there');
    }

    // --- Livewire integration --------------------------------------------

    public function test_owner_can_send_a_message_through_the_inbox_ui(): void
    {
        ['conversation' => $conversation, 'user' => $user] = $this->makeConversation();

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.UI1']]], 200)]);

        $this->actingAs($user);

        \Livewire\Volt\Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('message_content', 'Sent from the UI')
            ->call('sendMessage')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('messages', ['content' => 'Sent from the UI', 'provider_message_id' => 'wamid.UI1']);
    }

    public function test_a_provider_failure_surfaces_a_friendly_error_and_creates_no_message(): void
    {
        ['conversation' => $conversation, 'user' => $user] = $this->makeConversation();

        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], 500)]);

        $this->actingAs($user);

        \Livewire\Volt\Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('message_content', 'This will fail')
            ->call('sendMessage')
            ->assertHasErrors(['message_content']);

        $this->assertDatabaseMissing('messages', ['content' => 'This will fail']);
    }

    public function test_sending_with_no_active_whatsapp_account_configured_fails_gracefully(): void
    {
        $restaurant = Restaurant::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $restaurant->id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $restaurant->id, 'customer_id' => $customer->id]);
        $user = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $user->assignRole(Role::findOrCreate('owner'));

        $this->actingAs($user);

        \Livewire\Volt\Volt::test('inbox.conversations.show', ['conversation' => $conversation])
            ->set('message_content', 'No account configured')
            ->call('sendMessage')
            ->assertHasErrors(['message_content']);

        $this->assertDatabaseMissing('messages', ['content' => 'No account configured']);
    }

    public function test_kitchen_role_cannot_send_a_message(): void
    {
        ['conversation' => $conversation, 'restaurant' => $restaurant] = $this->makeConversation();
        $kitchen = User::factory()->create(['restaurant_id' => $restaurant->id]);
        $kitchen->assignRole(Role::findOrCreate('kitchen'));

        $this->actingAs($kitchen);

        $this->get(route('conversations.show', $conversation))->assertForbidden();
    }
}
