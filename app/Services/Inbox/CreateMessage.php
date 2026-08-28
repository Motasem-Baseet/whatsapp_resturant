<?php

namespace App\Services\Inbox;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Domain-level message creation, independent of how the message
 * originated (a local test message from the inbox UI today; a WhatsApp
 * webhook or an outbound-send job later). restaurant_id and
 * conversation_id are always taken from the given $conversation, never
 * from caller-supplied input - the database's composite foreign key on
 * (conversation_id, restaurant_id) is the final backstop either way.
 *
 * Wrapped in a transaction because it always updates two rows together
 * (the new message, and the conversation's last_message_at) and both
 * must succeed or neither should.
 */
class CreateMessage
{
    /**
     * @param  array{direction: MessageDirection|string, content?: ?string, provider_message_id?: ?string, sent_at?: ?Carbon, received_at?: ?Carbon}  $data
     */
    public function handle(Conversation $conversation, array $data): Message
    {
        return DB::transaction(function () use ($conversation, $data) {
            $message = new Message([
                'direction' => $data['direction'],
                'content' => $data['content'] ?? null,
                'provider_message_id' => $data['provider_message_id'] ?? null,
                'sent_at' => $data['sent_at'] ?? null,
                'received_at' => $data['received_at'] ?? null,
            ]);
            $message->restaurant_id = $conversation->restaurant_id;
            $message->conversation_id = $conversation->id;
            $message->save();

            $conversation->last_message_at = $message->sent_at ?? $message->received_at ?? now();
            $conversation->save();

            return $message;
        });
    }
}
