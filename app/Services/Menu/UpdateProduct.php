<?php

namespace App\Services\Menu;

use App\Models\Product;

/**
 * category_id (when changed) must already have been validated by the
 * caller to belong to the product's own restaurant - see
 * menu.products.edit. The database's composite foreign key is the final
 * backstop either way.
 */
class UpdateProduct
{
    /**
     * @param  array{category_id: int, name: string, description: ?string, price: string|float, is_active: bool}  $data
     */
    public function handle(Product $product, array $data): Product
    {
        $product->update($data);

        return $product;
    }
}
