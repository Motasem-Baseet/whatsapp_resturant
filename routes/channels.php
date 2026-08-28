<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Restaurant-scoped inbox channel - only a user belonging to that exact
// restaurant may subscribe, so WhatsApp message/status broadcasts never
// cross tenant boundaries. Also reuses ConversationPolicy::viewAny (the
// same "owner or cashier only" rule already enforced on the inbox
// routes/pages) rather than duplicating role names here, so kitchen
// users - who have never had inbox access - can't listen to inbox
// broadcasts either.
Broadcast::channel('restaurants.{restaurantId}.inbox', function ($user, $restaurantId) {
    return $user
        && (int) $user->restaurant_id === (int) $restaurantId
        && Gate::forUser($user)->allows('viewAny', Conversation::class);
});
