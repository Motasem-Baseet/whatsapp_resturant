<?php

namespace App\Services\WhatsApp;

use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Restaurant;
use App\Services\Customers\CreateCustomer;
use App\Services\Inbox\CreateConversation;
use App\Services\Inbox\CreateMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves/creates the customer and conversation for a single normalized
 * incoming message, then persists it - all inside one restaurant
 * boundary. Reuses the existing CreateCustomer, CreateConversation, and
 * CreateMessage services rather than duplicating their logic; this
 * class is the WhatsApp-specific glue between the provider and the
 * already-established, provider-agnostic domain layer.
 */
class ProcessIncomingMessage
{
    public function __construct(
        protected CreateCustomer $createCustomer,
        protected CreateConversation $createConversation,
        protected CreateMessage $createMessage,
    ) {}

    /**
     * @param  array{provider_message_id: string, sender_phone: string, sender_name: ?string, message_type: string, message_body: ?string, timestamp: ?\Illuminate\Support\Carbon}  $data
     * @return Message|null null when this exact provider_message_id was
     *         already processed by an earlier delivery of the same
     *         webhook (an idempotent replay, not an error).
     */
    public function handle(Restaurant $restaurant, array $data): ?Message
    {
        try {
            return DB::transaction(function () use ($restaurant, $data) {
                $customer = $this->resolveCustomer($restaurant, $data);
                $conversation = $this->resolveOpenConversation($restaurant, $customer);

                return $this->createMessage->handle($conversation, [
                    'direction' => MessageDirection::Inbound,
                    'content' => $data['message_body'],
                    'provider_message_id' => $data['provider_message_id'],
                    'received_at' => $data['timestamp'] ?? now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // The unique(restaurant_id, provider_message_id) constraint
            // is the sole authority on idempotency (see the messages
            // migration) - deliberately not pre-checked with
            // Message::where(...)->exists() first, since a check then
            // insert is itself race-prone under concurrent webhook
            // deliveries of the same message.
            return null;
        }
    }

    /**
     * Search only within the resolved restaurant - never globally - so
     * the same phone number legitimately remaining a distinct customer
     * per restaurant (the existing unique(restaurant_id, phone)
     * constraint from Phase 5) is preserved.
     */
    protected function resolveCustomer(Restaurant $restaurant, array $data): Customer
    {
        $phone = trim($data['sender_phone']);

        $customer = $restaurant->customers()->where('phone', $phone)->first();

        if ($customer) {
            if (blank($customer->name) && filled($data['sender_name'])) {
                $customer->name = $data['sender_name'];
                $customer->save();
            }

            return $customer;
        }

        try {
            return $this->createCustomer->handle($restaurant, [
                'name' => $data['sender_name'] ?: 'WhatsApp Customer',
                'phone' => $phone,
                'notes' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two near-simultaneous webhook deliveries for a brand new
            // customer raced each other; the unique(restaurant_id,
            // phone) constraint let exactly one insert through - this
            // re-fetches the row that constraint proves now exists,
            // rather than creating (or reporting) a duplicate.
            return $restaurant->customers()->where('phone', $phone)->firstOrFail();
        }
    }

    /**
     * Reuse the customer's open conversation if one exists; otherwise
     * create a new one. A closed conversation is never reopened - a new
     * open conversation is created instead, per the documented Phase 9
     * decision (closed means the restaurant considered that thread
     * finished; a customer messaging again starts a fresh thread rather
     * than silently reviving old context).
     *
     * lockForUpdate() is a best-effort mitigation against two
     * near-simultaneous webhook deliveries both deciding "no open
     * conversation exists yet" and creating two - it relies on InnoDB's
     * gap-locking under MySQL. There is no portable database constraint
     * available for "at most one open conversation per customer" (MySQL
     * has no partial/filtered unique index), so this is intentionally
     * transaction-level mitigation, not a hard guarantee - documented
     * here per the Phase 9 spec's explicit allowance for that trade-off.
     */
    protected function resolveOpenConversation(Restaurant $restaurant, Customer $customer): Conversation
    {
        $conversation = $customer->conversations()
            ->where('status', ConversationStatus::Open->value)
            ->lockForUpdate()
            ->latest('id')
            ->first();

        return $conversation ?? $this->createConversation->handle($restaurant, $customer);
    }
}
