<?php

namespace App\Services\Employees;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Creates a cashier or kitchen employee for the acting owner's own
 * restaurant.
 *
 * restaurant_id is always taken from the owner performing the action,
 * never from input - so there is no way to attach an employee to any
 * other restaurant. The role is likewise assigned from a value the
 * caller has already validated against ['cashier', 'kitchen'] only; this
 * class has no code path that can assign the owner role.
 */
class CreateEmployee
{
    /**
     * @param  array{name: string, email: string, password: string, role: string}  $data
     */
    public function handle(User $owner, array $data): User
    {
        $employee = new User([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $employee->restaurant_id = $owner->restaurant_id;
        $employee->is_active = true;
        $employee->save();

        $employee->assignRole(Role::findOrCreate($data['role']));

        return $employee;
    }
}
