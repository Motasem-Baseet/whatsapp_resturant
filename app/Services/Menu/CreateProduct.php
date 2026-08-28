<?php

namespace App\Services\Menu;

use App\Models\Product;
use App\Models\Restaurant;

/**
 * restaurant_id is taken explicitly from the given restaurant, not from
 * TenantContext - matching CreateEmployee's pattern. This makes the
 * service correct regardless of whether the current request happened to
 * run IdentifyTenant (e.g. it does not, in Livewire component tests
 * exercised via Volt::test(), which calls actions directly without
 * going through the HTTP middleware stack).
 *
 * category_id must already have been validated by the caller (see
 * menu.products.create) to belong to the current restaurant and be
 * active - this class trusts the array it is given, exactly like
 * CreateEmployee trusts its already-validated role. The database's
 * composite foreign key is the final backstop either way.
 */
class CreateProduct
{
    /**
     * @param  array{category_id: int, name: string, description: ?string, price: string|float, is_active?: bool}  $data
     */
    public function handle(Restaurant $restaurant, array $data): Product
    {
        $product = new Product($data);
        $product->restaurant_id = $restaurant->id;
        $product->save();

        return $product;
    }
}
