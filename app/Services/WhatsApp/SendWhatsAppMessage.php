<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Exceptions\WhatsAppMessageSendException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppAccount;
use App\Services\Inbox\CreateMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only place in the application that calls the WhatsApp Cloud API
 * to send a message. Takes its inputs as an explicit, fully-resolved
 * server-side contract (account, conversation, sending user, body) -
 * callers (the Livewire component) must not pass raw client input for
 * anything but the message body text.
 */
class SendWhatsAppMessage
{
    public function __construct(protected CreateMessage $createMessage) {}

    /**
     * @throws WhatsAppMessageSendException when the account does not
     *         belong to the conversation's restaurant, the conversation
     *         has no customer/phone to send to, or the provider call
     *         fails. A failed provider call never results in a Message
     *         row being created.
     */
    public function handle(WhatsAppAccount $account, Conversation $conversation, User $user, string $body): Message
    {
        if ($account->restaurant_id !== $conversation->restaurant_id) {
            // Defense in depth - the caller is expected to have already
            // resolved the account for this exact restaurant, but the
            // send boundary re-validates rather than trusting it.
            throw new WhatsAppMessageSendException('This WhatsApp account does not belong to this conversation\'s restaurant.');
        }

        $recipientPhone = $conversation->customer?->phone;

        if (blank($recipientPhone)) {
            throw new WhatsAppMessageSendException('This conversation has no customer phone number to send to.');
        }

        $baseUrl = rtrim(config('services.whatsapp.base_url'), '/');
        $version = config('services.whatsapp.graph_version');

        $response = Http::withToken($account->access_token)
            ->baseUrl("{$baseUrl}/{$version}")
            ->post("/{$account->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $recipientPhone,
                'type' => 'text',
                'text' => ['body' => $body],
            ]);

        if ($response->failed()) {
            Log::error('WhatsApp outbound message send failed.', [
                'restaurant_id' => $account->restaurant_id,
                'conversation_id' => $conversation->id,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            throw new WhatsAppMessageSendException('WhatsApp did not accept this message. Please try again.');
        }

        $providerMessageId = data_get($response->json(), 'messages.0.id');

        return $this->createMessage->handle($conversation, [
            'direction' => MessageDirection::Outbound,
            'content' => $body,
            'provider_message_id' => $providerMessageId,
            'status' => MessageStatus::Sent,
            'sent_at' => now(),
        ]);
    }
}
