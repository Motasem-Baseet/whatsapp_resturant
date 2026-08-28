<?php

namespace App\Services\Menu;

use App\Models\Category;
use App\Models\Restaurant;

/**
 * restaurant_id is taken explicitly from the given restaurant, not from
 * TenantContext - matching CreateEmployee's pattern. This makes the
 * service correct regardless of whether the current request happened to
 * run IdentifyTenant (e.g. it does not, in Livewire component tests
 * exercised via Volt::test(), which calls actions directly without
 * going through the HTTP middleware stack).
 */
class CreateCategory
{
    /**
     * @param  array{name: string}  $data
     */
    public function handle(Restaurant $restaurant, array $data): Category
    {
        $category = new Category($data);
        $category->restaurant_id = $restaurant->id;
        $category->save();

        return $category;
    }
}
