<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Restaurant;
use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    private function postWebhook(array $payload): TestResponse
    {
        return $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    }

    private function statusPayload(string $phoneNumberId, string $providerMessageId, string $status): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'statuses' => [[
                            'id' => $providerMessageId,
                            'status' => $status,
                            'timestamp' => (string) now()->timestamp,
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function outboundMessage(WhatsAppAccount $account, string $providerMessageId): Message
    {
        $customer = Customer::factory()->create(['restaurant_id' => $account->restaurant_id]);
        $conversation = Conversation::factory()->create(['restaurant_id' => $account->restaurant_id, 'customer_id' => $customer->id]);

        return Message::factory()->create([
            'restaurant_id' => $account->restaurant_id,
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Outbound,
            'provider_message_id' => $providerMessageId,
            'status' => MessageStatus::Sent,
        ]);
    }

    public function test_a_delivered_status_event_updates_the_matching_message(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $message = $this->outboundMessage($account, 'wamid.OUT1');

        $this->postWebhook($this->statusPayload($account->phone_number_id, 'wamid.OUT1', 'delivered'))->assertOk();

        $this->assertSame(MessageStatus::Delivered, $message->fresh()->status);
    }

    public function test_a_read_status_event_updates_the_matching_message(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $message = $this->outboundMessage($account, 'wamid.OUT2');

        $this->postWebhook($this->statusPayload($account->phone_number_id, 'wamid.OUT2', 'read'))->assertOk();

        $this->assertSame(MessageStatus::Read, $message->fresh()->status);
    }

    public function test_a_failed_status_event_updates_the_matching_message(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $message = $this->outboundMessage($account, 'wamid.OUT3');

        $this->postWebhook($this->statusPayload($account->phone_number_id, 'wamid.OUT3', 'failed'))->assertOk();

        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
    }

    public function test_a_status_event_for_an_unknown_provider_message_id_is_ignored_safely(): void
    {
        $account = WhatsAppAccount::factory()->create();

        $response = $this->postWebhook($this->statusPayload($account->phone_number_id, 'wamid.DOES-NOT-EXIST', 'delivered'));

        $response->assertOk();
    }

    public function test_a_status_event_cannot_update_a_message_belonging_to_another_restaurant(): void
    {
        $accountA = WhatsAppAccount::factory()->create();
        $accountB = WhatsAppAccount::factory()->create();
        $messageB = $this->outboundMessage($accountB, 'wamid.SHARED-ID');

        // Restaurant A's webhook claims a status update for a
        // provider_message_id that actually belongs to restaurant B -
        // must not touch restaurant B's message.
        $this->postWebhook($this->statusPayload($accountA->phone_number_id, 'wamid.SHARED-ID', 'delivered'))->assertOk();

        $this->assertSame(MessageStatus::Sent, $messageB->fresh()->status);
    }

    public function test_an_unrecognized_status_keyword_is_ignored_without_error(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $message = $this->outboundMessage($account, 'wamid.OUT4');

        $response = $this->postWebhook($this->statusPayload($account->phone_number_id, 'wamid.OUT4', 'some_future_status'));

        $response->assertOk();
        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
    }
}
