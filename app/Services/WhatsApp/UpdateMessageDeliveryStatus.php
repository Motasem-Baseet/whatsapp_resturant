<?php

namespace App\Services\WhatsApp;

use App\Enums\MessageStatus;
use App\Events\MessageStatusUpdated;
use App\Models\Message;
use App\Models\Restaurant;

/**
 * Applies a delivery-status webhook event (sent/delivered/read/failed)
 * to the matching outbound Message. Lookup is scoped to
 * provider_message_id + restaurant so one tenant's status update can
 * never touch another tenant's message, even if a provider_message_id
 * were somehow guessed or replayed cross-tenant.
 */
class UpdateMessageDeliveryStatus
{
    protected const STATUS_MAP = [
        'sent' => MessageStatus::Sent,
        'delivered' => MessageStatus::Delivered,
        'read' => MessageStatus::Read,
        'failed' => MessageStatus::Failed,
    ];

    /**
     * @param  array{provider_message_id: string, status: string, timestamp: ?\Illuminate\Support\Carbon}  $data
     */
    public function handle(Restaurant $restaurant, array $data): ?Message
    {
        $status = self::STATUS_MAP[$data['status']] ?? null;

        if ($status === null) {
            // Unrecognized status keyword - nothing provider-neutral to
            // record; ignored rather than crashing the webhook.
            return null;
        }

        $message = $restaurant->messages()
            ->where('provider_message_id', $data['provider_message_id'])
            ->first();

        if (! $message) {
            // Status event for a message this restaurant has no record
            // of (e.g. arrived before the send response was persisted,
            // or references a message outside this tenant) - ignored.
            return null;
        }

        $message->status = $status;
        $message->save();

        MessageStatusUpdated::dispatch($message);

        return $message;
    }
}
