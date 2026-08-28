<?php

namespace App\Services\Customers;

use App\Models\Customer;

/**
 * restaurant_id is never part of $data here - it is not fillable on
 * Customer, and this service never assigns it, so a customer can never
 * move restaurants through an edit.
 */
class UpdateCustomer
{
    /**
     * @param  array{name: string, phone: string, notes: ?string}  $data
     */
    public function handle(Customer $customer, array $data): Customer
    {
        $customer->update($data);

        return $customer;
    }
}
