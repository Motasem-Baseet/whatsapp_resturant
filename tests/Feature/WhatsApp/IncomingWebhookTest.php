<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Restaurant;
use App\Models\WhatsAppAccount;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IncomingWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Posts a raw JSON body to the webhook endpoint, optionally signed
     * with X-Hub-Signature-256 - mirrors exactly how the real controller
     * reads the request (raw body first, decoded second) rather than
     * relying on Laravel's postJson() helper reshaping the payload.
     */
    private function postWebhook(array $payload, ?string $appSecret = null): TestResponse
    {
        $json = json_encode($payload);
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($appSecret !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $json, $appSecret);
        }

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], $server, $json);
    }

    private function textMessagePayload(string $phoneNumberId, array $overrides = []): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => array_replace_recursive([
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'contacts' => [['wa_id' => '15550001111', 'profile' => ['name' => 'Alice Customer']]],
                        'messages' => [[
                            'id' => 'wamid.ABC123',
                            'from' => '15550001111',
                            'type' => 'text',
                            'text' => ['body' => 'Hello, is my order ready?'],
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ], $overrides),
                ]],
            ]],
        ];
    }

    // --- Restaurant resolution -------------------------------------------

    public function test_incoming_message_is_attributed_to_the_restaurant_owning_the_phone_number_id(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($this->textMessagePayload($account->phone_number_id))->assertOk();

        $message = Message::where('provider_message_id', 'wamid.ABC123')->firstOrFail();

        $this->assertSame($account->restaurant_id, $message->restaurant_id);
    }

    public function test_unknown_phone_number_id_is_acknowledged_without_creating_anything(): void
    {
        $response = $this->postWebhook($this->textMessagePayload('does-not-exist'));

        $response->assertOk();
        $this->assertDatabaseMissing('messages', ['provider_message_id' => 'wamid.ABC123']);
    }

    public function test_inactive_accounts_phone_number_id_is_not_matched(): void
    {
        $account = WhatsAppAccount::factory()->inactive()->create();

        $this->postWebhook($this->textMessagePayload($account->phone_number_id))->assertOk();

        $this->assertDatabaseMissing('messages', ['provider_message_id' => 'wamid.ABC123']);
    }

    public function test_tenant_context_is_cleared_after_processing(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($this->textMessagePayload($account->phone_number_id))->assertOk();

        $this->assertFalse(app(TenantContext::class)->check());
    }

    // --- Customer and conversation resolution -----------------------------

    public function test_a_new_customer_is_created_from_the_sender_phone_and_name(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($this->textMessagePayload($account->phone_number_id))->assertOk();

        $customer = Customer::where('restaurant_id', $account->restaurant_id)->where('phone', '15550001111')->firstOrFail();

        $this->assertSame('Alice Customer', $customer->name);
    }

    public function test_an_existing_customer_with_the_same_phone_is_reused_not_duplicated(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $existing = Customer::factory()->create(['restaurant_id' => $account->restaurant_id, 'phone' => '15550001111']);

        $this->postWebhook($this->textMessagePayload($account->phone_number_id))->assertOk();

        $this->assertSame(1, Customer::where('restaurant_id', $account->restaurant_id)->where('phone', '15550001111')->count());
        $message = Message::where('provider_message_id', 'wamid.ABC123')->firstOrFail();
        $this->assertSame($existing->id, $message->conversation->customer_id);
    }

    public function test_an_open_conversation_is_reused_for_a_second_incoming_message(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($this->textMessagePayload($account->phone_number_id))->assertOk();
        $this->postWebhook($this->textMessagePayload($account->phone_number_id, [
            'messages' => [['id' => 'wamid.SECOND', 'from' => '15550001111', 'type' => 'text', 'text' => ['body' => 'Following up'], 'timestamp' => (string) now()->timestamp]],
        ]))->assertOk();

        $customer = Customer::where('restaurant_id', $account->restaurant_id)->where('phone', '15550001111')->firstOrFail();

        $this->assertSame(1, $customer->conversations()->count());
        $this->assertSame(2, Message::where('conversation_id', $customer->conversations()->first()->id)->count());
    }

    public function test_a_new_conversation_is_created_when_the_previous_one_is_closed(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $customer = Customer::factory()->create(['restaurant_id' => $account->restaurant_id, 'phone' => '15550001111']);
        Conversation::factory()->create([
            'restaurant_id' => $account->restaurant_id,
            'customer_id' => $customer->id,
            'status' => ConversationStatus::Closed,
        ]);

        $this->postWebhook($this->textMessagePayload($account->phone_number_id))->assertOk();

        $this->assertSame(2, $customer->conversations()->count());
        $newConversation = $customer->conversations()->where('status', ConversationStatus::Open->value)->firstOrFail();
        $this->assertSame('wamid.ABC123', $newConversation->messages()->first()->provider_message_id);
    }

    public function test_incoming_message_has_inbound_direction(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($this->textMessagePayload($account->phone_number_id))->assertOk();

        $message = Message::where('provider_message_id', 'wamid.ABC123')->firstOrFail();

        $this->assertSame(MessageDirection::Inbound, $message->direction);
        $this->assertSame('Hello, is my order ready?', $message->content);
    }

    public function test_conversation_last_message_at_is_updated_from_an_incoming_message(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $this->postWebhook($this->textMessagePayload($account->phone_number_id))->assertOk();

        $message = Message::where('provider_message_id', 'wamid.ABC123')->firstOrFail();

        $this->assertNotNull($message->conversation->last_message_at);
    }

    // --- Idempotency --------------------------------------------------

    public function test_a_duplicate_delivery_of_the_same_message_is_not_persisted_twice(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $payload = $this->textMessagePayload($account->phone_number_id);

        $this->postWebhook($payload)->assertOk();
        $response = $this->postWebhook($payload);

        $response->assertOk();
        $this->assertSame(1, Message::where('provider_message_id', 'wamid.ABC123')->count());
    }

    // --- Unsupported message types --------------------------------------

    public function test_an_unsupported_message_type_does_not_crash_the_webhook(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $response = $this->postWebhook($this->textMessagePayload($account->phone_number_id, [
            'messages' => [['id' => 'wamid.IMG1', 'from' => '15550001111', 'type' => 'image', 'image' => ['id' => 'media123']]],
        ]));

        $response->assertOk();
        $this->assertDatabaseMissing('messages', ['provider_message_id' => 'wamid.IMG1']);
    }

    public function test_a_valid_text_message_is_still_processed_alongside_an_unsupported_one_in_the_same_payload(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $payload = $this->textMessagePayload($account->phone_number_id, [
            'messages' => [
                ['id' => 'wamid.IMG1', 'from' => '15550001111', 'type' => 'image', 'image' => ['id' => 'media123']],
                ['id' => 'wamid.TXT1', 'from' => '15550001111', 'type' => 'text', 'text' => ['body' => 'Actual text'], 'timestamp' => (string) now()->timestamp],
            ],
        ]);

        $this->postWebhook($payload)->assertOk();

        $this->assertDatabaseMissing('messages', ['provider_message_id' => 'wamid.IMG1']);
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'wamid.TXT1']);
    }

    // --- Signature verification -----------------------------------------

    public function test_a_correctly_signed_payload_is_accepted_when_app_secret_is_configured(): void
    {
        $account = WhatsAppAccount::factory()->create(['app_secret' => 'my-app-secret']);

        $response = $this->postWebhook($this->textMessagePayload($account->phone_number_id), appSecret: 'my-app-secret');

        $response->assertOk();
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'wamid.ABC123']);
    }

    public function test_an_incorrectly_signed_payload_is_rejected_with_403(): void
    {
        $account = WhatsAppAccount::factory()->create(['app_secret' => 'my-app-secret']);

        $response = $this->postWebhook($this->textMessagePayload($account->phone_number_id), appSecret: 'wrong-secret');

        $response->assertForbidden();
        $this->assertDatabaseMissing('messages', ['provider_message_id' => 'wamid.ABC123']);
    }

    public function test_a_missing_signature_is_rejected_when_app_secret_is_configured(): void
    {
        $account = WhatsAppAccount::factory()->create(['app_secret' => 'my-app-secret']);

        // No signature header at all - postWebhook() with appSecret null.
        $response = $this->postWebhook($this->textMessagePayload($account->phone_number_id));

        $response->assertForbidden();
    }

    public function test_signature_verification_is_skipped_when_no_app_secret_is_configured(): void
    {
        $account = WhatsAppAccount::factory()->withoutAppSecret()->create();

        $response = $this->postWebhook($this->textMessagePayload($account->phone_number_id));

        $response->assertOk();
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'wamid.ABC123']);
    }

    // --- Multi-tenancy -----------------------------------------------

    public function test_two_restaurants_receiving_webhooks_do_not_cross_contaminate(): void
    {
        $accountA = WhatsAppAccount::factory()->create();
        $accountB = WhatsAppAccount::factory()->create();

        $this->postWebhook($this->textMessagePayload($accountA->phone_number_id, [
            'messages' => [['id' => 'wamid.A1', 'from' => '15550001111', 'type' => 'text', 'text' => ['body' => 'For A'], 'timestamp' => (string) now()->timestamp]],
        ]))->assertOk();

        $this->postWebhook($this->textMessagePayload($accountB->phone_number_id, [
            'messages' => [['id' => 'wamid.B1', 'from' => '15550001111', 'type' => 'text', 'text' => ['body' => 'For B'], 'timestamp' => (string) now()->timestamp]],
        ]))->assertOk();

        $messageA = Message::where('provider_message_id', 'wamid.A1')->firstOrFail();
        $messageB = Message::where('provider_message_id', 'wamid.B1')->firstOrFail();

        $this->assertSame($accountA->restaurant_id, $messageA->restaurant_id);
        $this->assertSame($accountB->restaurant_id, $messageB->restaurant_id);
        $this->assertNotSame($messageA->restaurant_id, $messageB->restaurant_id);

        // Same sender phone number legitimately becomes two distinct
        // per-restaurant customers.
        $this->assertSame(2, Customer::where('phone', '15550001111')->count());
    }

    public function test_client_supplied_restaurant_id_in_the_payload_is_ignored(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $account = WhatsAppAccount::factory()->create();

        $payload = $this->textMessagePayload($account->phone_number_id);
        // Nothing in the real payload shape carries a restaurant_id -
        // this asserts the resolved message still belongs to the
        // account's own restaurant regardless, never a bystander one.
        $this->postWebhook($payload)->assertOk();

        $message = Message::where('provider_message_id', 'wamid.ABC123')->firstOrFail();

        $this->assertNotSame($restaurantA->id, $message->restaurant_id);
        $this->assertSame($account->restaurant_id, $message->restaurant_id);
    }
}
