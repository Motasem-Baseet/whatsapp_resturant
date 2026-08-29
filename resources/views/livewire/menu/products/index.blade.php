<?php

use App\Models\Product;
use App\Services\Menu\UpdateProduct;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);
    }

    #[Computed]
    public function products()
    {
        return Auth::user()->restaurant
            ->products()
            ->with('category')
            ->orderBy('name')
            ->get();
    }

    /**
     * Quick owner-only availability toggle (Phase 27) - re-authorizes
     * at call time rather than trusting page access alone, and the
     * product is always resolved from the current restaurant's own
     * products() relationship, never from a bare Product::find(), so a
     * forged id from another restaurant 404s instead of ever reaching
     * UpdateProduct. Only flips is_available; is_active, price, stock,
     * and every historical order/order item are untouched.
     */
    public function toggleAvailability(int $productId): void
    {
        $product = Auth::user()->restaurant->products()->findOrFail($productId);

        $this->authorize('update', $product);

        app(UpdateProduct::class)->handle($product, ['is_available' => ! $product->is_available]);

        unset($this->products);
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Products') }}</flux:heading>
            <flux:subheading>{{ __('Manage the items on your menu.') }}</flux:subheading>
        </div>

        <flux:button :href="route('menu.products.create')" variant="primary" wire:navigate>
            {{ __('Add product') }}
        </flux:button>
    </div>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Category') }}</flux:table.column>
            <flux:table.column>{{ __('Price') }}</flux:table.column>
            <flux:table.column>{{ __('Stock') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->products as $product)
                <flux:table.row wire:key="product-{{ $product->id }}">
                    <flux:table.cell>{{ $product->name }}</flux:table.cell>
                    <flux:table.cell>{{ $product->category->name }}</flux:table.cell>
                    <flux:table.cell>{{ number_format($product->price, 2) }}</flux:table.cell>
                    <flux:table.cell>{{ $product->stock_quantity === null ? __('Unlimited') : $product->stock_quantity }}</flux:table.cell>
                    <flux:table.cell>
                        @if (! $product->is_active)
                            <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                        @elseif (! $product->is_available)
                            <flux:badge color="amber" size="sm">{{ __('Unavailable') }}</flux:badge>
                        @elseif ($product->stock_quantity !== null && $product->stock_quantity <= 0)
                            <flux:badge color="red" size="sm">{{ __('Out of Stock') }}</flux:badge>
                        @else
                            <flux:badge color="green" size="sm">{{ __('Available') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-2">
                            <flux:button :href="route('menu.products.edit', $product)" size="sm" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button wire:click="toggleAvailability({{ $product->id }})" size="sm" variant="ghost">
                                {{ $product->is_available ? __('Mark unavailable') : __('Mark available') }}
                            </flux:button>
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">
                        {{ __('No products yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
