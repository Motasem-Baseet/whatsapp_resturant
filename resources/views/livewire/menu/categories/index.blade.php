<?php

use App\Models\Category;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', Category::class);
    }

    #[Computed]
    public function categories()
    {
        return Auth::user()->restaurant
            ->categories()
            ->withCount('products')
            ->orderBy('name')
            ->get();
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Categories') }}</flux:heading>
            <flux:subheading>{{ __('Organize your menu into categories.') }}</flux:subheading>
        </div>

        <flux:button :href="route('menu.categories.create')" variant="primary" wire:navigate>
            {{ __('Add category') }}
        </flux:button>
    </div>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Products') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->categories as $category)
                <flux:table.row wire:key="category-{{ $category->id }}">
                    <flux:table.cell>{{ $category->name }}</flux:table.cell>
                    <flux:table.cell>{{ $category->products_count }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($category->is_active)
                            <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button :href="route('menu.categories.edit', $category)" size="sm" wire:navigate>
                            {{ __('Edit') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="text-center text-zinc-500">
                        {{ __('No categories yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
