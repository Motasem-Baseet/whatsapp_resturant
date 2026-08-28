<?php

namespace App\Services\Inbox;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Restaurant;

/**
 * restaurant_id is taken explicitly from the given restaurant, not from
 * TenantContext - matching the pattern established from Phase 4 onward
 * (CreateCategory, CreateProduct, CreateCustomer). The caller must
 * already have validated that $customer belongs to $restaurant (see
 * inbox.conversations.create); the database's composite foreign key on
 * (customer_id, restaurant_id) is the final backstop either way.
 *
 * Intended to be reusable outside the HTTP/Livewire layer too - a
 * future WhatsApp webhook handler would call this same service once it
 * has resolved (or created) the Customer for an inbound message.
 */
class CreateConversation
{
    public function handle(Restaurant $restaurant, Customer $customer): Conversation
    {
        $conversation = new Conversation([
            'status' => ConversationStatus::Open,
        ]);
        $conversation->restaurant_id = $restaurant->id;
        $conversation->customer_id = $customer->id;
        $conversation->save();

        return $conversation;
    }
}
