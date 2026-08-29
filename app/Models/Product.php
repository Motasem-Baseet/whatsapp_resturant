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
        'is_available',
        'stock_quantity',
    ];

    /**
     * Default attribute values for new, in-memory instances.
     *
     * is_available mirrors the database column's own default (true), so
     * a freshly created model already reflects it in PHP without
     * needing a round-trip refresh from the database - the same gap
     * fixed for User::$is_active in Phase 3. stock_quantity needs no
     * entry here: its "unset" PHP value is already null, matching the
     * column's own nullable default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_available' => true,
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
            'is_available' => 'boolean',
            'stock_quantity' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Whether this product can currently be added to a new order
     * (Phase 27) - the single source of truth used by both the product
     * selector (App\Livewire\Concerns\HasProductSelection) and
     * CreateOrder's own server-side re-validation, so the two can never
     * disagree.
     *
     * is_active (generally enabled in the system) and is_available
     * (currently orderable, independent of is_active) are deliberately
     * separate conditions - a product can be temporarily marked
     * unavailable without deactivating it. stock_quantity of null means
     * "not stock-tracked", which is never treated as zero stock - a
     * non-null value must be strictly greater than zero.
     */
    public function isOrderable(): bool
    {
        return $this->is_active
            && $this->is_available
            && $this->category?->is_active === true
            && ($this->stock_quantity === null || $this->stock_quantity > 0);
    }
}
