<?php

namespace App\Services\Restaurants;

use App\Models\Restaurant;

/**
 * Updates a restaurant's own profile fields (name, phone, address,
 * logo_path - the only columns the restaurants table actually has
 * beyond its primary key/timestamps).
 *
 * Deliberately receives an already-resolved, trusted Restaurant model
 * rather than any kind of id - the caller (the settings page) is
 * responsible for that resolution, and must always derive it from
 * Auth::user()->restaurant, never from request input. This service does
 * not, and must not, have any way to target a different restaurant than
 * the one it's given.
 */
class UpdateRestaurant
{
    /**
     * @param  array{name: string, phone: string, address: string, logo_path: ?string}  $data
     */
    public function handle(Restaurant $restaurant, array $data): Restaurant
    {
        $restaurant->fill($data);
        $restaurant->save();

        return $restaurant;
    }
}
