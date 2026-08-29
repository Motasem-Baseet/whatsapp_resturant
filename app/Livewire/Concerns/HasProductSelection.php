<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;

/**
 * Shared product-search/category-filter/quantity-selection behavior for
 * the two order-creation pages (direct orders.create and the WhatsApp
 * conversation order-create page). Both pages previously implemented
 * near-identical product listing, "add item", and "remove item" logic
 * independently - this trait is the single place that logic now lives,
 * so the two pages cannot drift apart.
 *
 * This trait owns UI/selection *state* only - product_search,
 * category_id, and the running $items array are for building the
 * request to CreateOrder, never for computing anything authoritative.
 * unit price/line total/subtotal/total are never stored or calculated
 * here; 'price' in $items is carried purely for the page's own
 * "estimated" display and is never read by the host component when it
 * calls CreateOrder (see each host component's save()).
 *
 * Deliberately a plain trait rather than a nested Livewire/Volt child
 * component: Volt pages are single-file components, and introducing a
 * second, separately-mounted stateful component here would require
 * event-based state synchronization for no real benefit - a trait
 * sharing properties and methods directly on the same component class
 * is the simplest form of reuse that still eliminates the duplication.
 */
trait HasProductSelection
{
    public string $product_search = '';
    public string $category_id = '';

    public string $selected_product_id = '';
    public int $selected_quantity = 1;

    /** @var array<int, array{product_id:int, name:string, price:string, quantity:int}> */
    public array $items = [];

    #[Computed]
    public function availableCategories()
    {
        return Auth::user()->restaurant
            ->categories()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Only currently orderable products (Phase 27: active, available,
     * in an active category, and in stock or untracked - see
     * Product::isOrderable(), the single source of truth this and
     * CreateOrder::resolveOrderableProducts() both defer to), belonging
     * to the current restaurant - narrowed further by the search term
     * and/or category filter, both optional.
     *
     * Filtered in PHP via isOrderable() rather than re-expressing the
     * same conditions as a second, hand-written SQL predicate here -
     * for a single restaurant's own menu this stays a small dataset,
     * and it guarantees this list can never drift from what CreateOrder
     * will itself accept.
     *
     * The category filter never trusts category_id directly: an
     * unrecognized or foreign id is treated as "no category selected"
     * by validSelectedCategoryId() rather than being passed into the
     * query, so a forged id can never reveal another restaurant's
     * products (there would be none matching this restaurant's
     * products() relationship anyway, but this also avoids silently
     * returning zero results for a typo'd id when "all categories"
     * would have been the more useful fallback).
     */
    #[Computed]
    public function availableProducts()
    {
        $categoryId = $this->validSelectedCategoryId();

        return Auth::user()->restaurant
            ->products()
            ->with('category')
            ->when($this->product_search !== '', fn ($query) => $query->where('name', 'like', '%'.$this->product_search.'%'))
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId))
            ->orderBy('name')
            ->get()
            ->filter(fn ($product) => $product->isOrderable())
            ->values();
    }

    protected function validSelectedCategoryId(): ?int
    {
        if ($this->category_id === '') {
            return null;
        }

        $belongsToRestaurant = Auth::user()->restaurant
            ->categories()
            ->where('id', $this->category_id)
            ->where('is_active', true)
            ->exists();

        return $belongsToRestaurant ? (int) $this->category_id : null;
    }

    /**
     * Adds (or merges into) a line item. The price stored here is for
     * display only (an estimated running total on this page) - the
     * server recalculates everything from the product record when the
     * order is actually created, so a forged value here changes nothing
     * about what gets charged.
     */
    public function addItem(): void
    {
        $this->validate([
            'selected_product_id' => ['required', Rule::in($this->availableProducts->pluck('id')->all())],
            'selected_quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], ['selected_product_id' => __('product')]);

        $product = $this->availableProducts->firstWhere('id', (int) $this->selected_product_id);
        $productId = (int) $this->selected_product_id;

        if (isset($this->items[$productId])) {
            $this->items[$productId]['quantity'] += $this->selected_quantity;
        } else {
            $this->items[$productId] = [
                'product_id' => $productId,
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => $this->selected_quantity,
            ];
        }

        $this->selected_product_id = '';
        $this->selected_quantity = 1;
    }

    /**
     * UI-only convenience for a selected line's quantity - CreateOrder
     * is still the final authority regardless of what quantity ends up
     * in $items by the time save() runs (it re-validates/normalizes
     * every quantity itself; see each host component's save()).
     */
    public function incrementQuantity(int $productId): void
    {
        if (! isset($this->items[$productId])) {
            return;
        }

        $this->items[$productId]['quantity']++;
    }

    /**
     * Never lets a selected line drop below quantity 1 through the UI -
     * removeItem() is the explicit action for dropping a line entirely.
     */
    public function decrementQuantity(int $productId): void
    {
        if (! isset($this->items[$productId]) || $this->items[$productId]['quantity'] <= 1) {
            return;
        }

        $this->items[$productId]['quantity']--;
    }

    public function removeItem(int $productId): void
    {
        unset($this->items[$productId]);
    }

    /**
     * The only shape CreateOrder ever receives from either page -
     * product_id and quantity, nothing else. Even if 'price' inside
     * $items were forged, it never reaches this array.
     *
     * @return array<int, array{product_id: int, quantity: int}>
     */
    protected function itemsForOrder(): array
    {
        return array_map(
            fn ($item) => ['product_id' => $item['product_id'], 'quantity' => $item['quantity']],
            array_values($this->items),
        );
    }
}
