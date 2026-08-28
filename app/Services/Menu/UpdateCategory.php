<?php

namespace App\Services\Menu;

use App\Models\Category;

class UpdateCategory
{
    /**
     * @param  array{name: string, is_active: bool}  $data
     */
    public function handle(Category $category, array $data): Category
    {
        $category->update($data);

        return $category;
    }
}
