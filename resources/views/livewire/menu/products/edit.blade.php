<?php

use App\Models\Product;
use App\Services\Menu\UpdateProduct;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Product $product;

    public string $category_id = '';
    public string $name = '';
    public string $description = '';
    public string $price = '';
    public bool $is_active = true;
    public bool $is_available = true;

    /**
     * Blank when the product is not stock-tracked (stock_quantity is
     * null) - left blank and saved unchanged, this preserves that
     * untracked state rather than silently coercing it to zero the
     * next time an unrelated field on this product is edited.
     */
    public string $stock_quantity = '';

    public function mount(Product $product): void
    {
        $this->authorize('update', $product);

        $this->product = $product;
        $this->category_id = (string) $product->category_id;
        $this->name = $product->name;
        $this->description = (string) $product->description;
        $this->price = (string) $product->price;
        $this->is_active = $product->is_active;
        $this->is_available = $product->is_available;
        $this->stock_quantity = $product->stock_quantity !== null ? (string) $product->stock_quantity : '';
    }

    /**
     * Categories belonging to the current restaurant. Unlike creation,
     * editing also lists inactive categories, so a product keeps
     * showing its current category even if that category was
     * deactivated afterwards - it just can't be switched to a
     * *different* inactive one (enforced by validation below).
     */
    #[Computed]
    public function categories()
    {
        return Auth::user()->restaurant
            ->categories()
            ->orderBy('name')
            ->get();
    }

    /**
     * Update this product. Re-checks authorization here too, since this
     * action runs on its own AJAX request and does not re-run mount().
     * category_id is validated to belong to the current restaurant
     * before it ever reaches the database; the products table's
     * composite foreign key is the final backstop either way.
     */
    public function save(): void
    {
        $this->authorize('update', $this->product);

        $validated = $this->validate([
            'category_id' => [
                'required',
                Rule::exists('categories', 'id')->where('restaurant_id', Auth::user()->restaurant_id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'gt:0'],
            'is_active' => ['required', 'boolean'],
            'is_available' => ['required', 'boolean'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        // '' (blank) must survive as null, not 0 - a legitimate zero
        // stock quantity is a real, meaningful value, unlike "not
        // provided"/"not tracked".
        $validated['stock_quantity'] = $validated['stock_quantity'] !== '' && $validated['stock_quantity'] !== null
            ? (int) $validated['stock_quantity']
            : null;

        app(UpdateProduct::class)->handle($this->product, $validated);

        session()->flash('status', __('Product updated.'));

        $this->redirect(route('menu.products.index'), navigate: true);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Edit product') }}</flux:heading>
    <flux:subheading>{{ __('Update this menu item.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 flex flex-col gap-6">
        <flux:select wire:model="category_id" label="{{ __('Category') }}">
            @foreach ($this->categories as $category)
                <flux:select.option value="{{ $category->id }}">
                    {{ $category->name }}{{ $category->is_active ? '' : ' ('.__('inactive').')' }}
                </flux:select.option>
            @endforeach
        </flux:select>

        <flux:input wire:model="name" label="{{ __('Name') }}" type="text" required autofocus />

        <flux:textarea wire:model="description" label="{{ __('Description') }}" />

        <flux:input wire:model="price" label="{{ __('Price') }}" type="number" step="0.01" min="0.01" required />

        <div>
            <flux:input wire:model="stock_quantity" label="{{ __('Stock quantity') }}" type="number" step="1" min="0" placeholder="{{ __('Leave blank to skip stock tracking') }}" />
            <p class="mt-1 text-xs text-zinc-500">{{ __('Leave blank for unlimited/untracked stock - the product will remain orderable regardless of quantity.') }}</p>
        </div>

        <flux:switch wire:model="is_active" label="{{ __('Active') }}" />
        <flux:switch wire:model="is_available" label="{{ __('Available for ordering') }}" />

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
            <flux:button :href="route('menu.products.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
