<?php

namespace App\Services\Auth;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Creates a new restaurant together with its owner user, atomically.
 *
 * Both records - and the owner role assignment - are created inside a
 * single database transaction, so a failure at any step (e.g. a duplicate
 * email slipping past validation) leaves neither an orphan restaurant nor
 * a half-linked user behind.
 *
 * restaurant_id and the owner role are always derived here, server-side -
 * never accepted as input - so a caller has no way to make a user land on
 * a restaurant, or receive a role, that this class did not itself create.
 */
class RegisterRestaurantOwner
{
    /**
     * @param  array{name: string, email: string, password: string}  $owner
     * @param  array{name: string, phone: string, address: string}  $restaurant
     */
    public function handle(array $owner, array $restaurant): User
    {
        return DB::transaction(function () use ($owner, $restaurant) {
            $restaurant = Restaurant::create([
                'name' => $restaurant['name'],
                'phone' => $restaurant['phone'],
                'address' => $restaurant['address'],
            ]);

            $user = new User([
                'name' => $owner['name'],
                'email' => $owner['email'],
                'password' => Hash::make($owner['password']),
            ]);
            $user->restaurant_id = $restaurant->id;
            $user->save();

            $user->assignRole(Role::findOrCreate('owner'));

            return $user;
        });
    }
}
