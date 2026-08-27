<?php

namespace App\Services\Employees;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Updates a cashier or kitchen employee's editable fields.
 *
 * restaurant_id is never touched here - the employee stays on whichever
 * restaurant they were created for. The role is synced from a value the
 * caller has already validated against ['cashier', 'kitchen'] only, so
 * this can never promote an employee to owner.
 */
class UpdateEmployee
{
    /**
     * @param  array{name: string, email: string, role: string, is_active: bool}  $data
     */
    public function handle(User $employee, array $data): User
    {
        $employee->name = $data['name'];
        $employee->email = $data['email'];
        $employee->is_active = $data['is_active'];
        $employee->save();

        $employee->syncRoles([Role::findOrCreate($data['role'])]);

        return $employee;
    }
}
