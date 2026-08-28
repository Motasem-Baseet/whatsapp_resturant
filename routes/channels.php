<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Restaurant-scoped inbox channel - only a user belonging to that exact
// restaurant may subscribe, so WhatsApp message/status broadcasts never
// cross tenant boundaries.
Broadcast::channel('restaurants.{restaurantId}.inbox', function ($user, $restaurantId) {
    return $user && (int) $user->restaurant_id === (int) $restaurantId;
});
