<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use BelongsToRestaurant, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * restaurant_id is deliberately excluded - it is always assigned by
     * BelongsToRestaurant from the current tenant, never from input.
     * category_id is fillable, but every caller must validate it (see
     * the menu.products.create/edit components) before it ever reaches
     * this model - the database's composite foreign key to
     * categories(id, restaurant_id) is the final backstop if that
     * validation is ever bypassed.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'description',
        'price',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
