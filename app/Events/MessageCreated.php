<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on a restaurant-scoped private channel only - never a
 * global/public channel - so one tenant's inbox activity can never be
 * observed by another tenant's browser session. Fired for both inbound
 * (webhook) and outbound (owner/cashier sent) messages via the shared
 * CreateMessage service, so this single event covers both directions.
 */
class MessageCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("restaurants.{$this->message->restaurant_id}.inbox"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'direction' => $this->message->direction->value,
            'content' => $this->message->content,
            'status' => $this->message->status?->value,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
