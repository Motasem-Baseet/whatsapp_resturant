<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Restaurant;

/**
 * restaurant_id is taken explicitly from the given restaurant, not from
 * TenantContext - matching CreateEmployee/CreateCategory/CreateProduct.
 * This keeps the service correct regardless of whether the current
 * request happened to run IdentifyTenant (it does not in Livewire
 * component tests exercised via Volt::test(), which calls actions
 * directly without going through the HTTP middleware stack).
 */
class CreateCustomer
{
    /**
     * @param  array{name: string, phone: string, notes: ?string}  $data
     */
    public function handle(Restaurant $restaurant, array $data): Customer
    {
        $customer = new Customer($data);
        $customer->restaurant_id = $restaurant->id;
        $customer->save();

        return $customer;
    }
}
