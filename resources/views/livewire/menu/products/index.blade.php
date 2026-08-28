<?php

use App\Models\Product;
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
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->products as $product)
                <flux:table.row wire:key="product-{{ $product->id }}">
                    <flux:table.cell>{{ $product->name }}</flux:table.cell>
                    <flux:table.cell>{{ $product->category->name }}</flux:table.cell>
                    <flux:table.cell>{{ number_format($product->price, 2) }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($product->is_active)
                            <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button :href="route('menu.products.edit', $product)" size="sm" wire:navigate>
                            {{ __('Edit') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                        {{ __('No products yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
