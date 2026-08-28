<?php

use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Customer::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Customers belonging to the current restaurant, optionally filtered
     * by name or phone. The base query is always scoped through the
     * current user's own restaurant relationship, so search can never
     * surface another restaurant's customers.
     */
    #[Computed]
    public function customers()
    {
        return Auth::user()->restaurant
            ->customers()
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    $query->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(15);
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Customers') }}</flux:heading>
            <flux:subheading>{{ __('People who order from your restaurant.') }}</flux:subheading>
        </div>

        @can('create', Customer::class)
            <flux:button :href="route('customers.create')" variant="primary" wire:navigate>
                {{ __('Add customer') }}
            </flux:button>
        @endcan
    </div>

    <flux:input
        wire:model.live.debounce.300ms="search"
        type="search"
        placeholder="{{ __('Search by name or phone...') }}"
        class="mt-6 max-w-sm"
    />

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Phone') }}</flux:table.column>
            <flux:table.column>{{ __('Notes') }}</flux:table.column>
            <flux:table.column>{{ __('Added') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->customers as $customer)
                <flux:table.row wire:key="customer-{{ $customer->id }}">
                    <flux:table.cell>{{ $customer->name }}</flux:table.cell>
                    <flux:table.cell>{{ $customer->phone }}</flux:table.cell>
                    <flux:table.cell class="max-w-xs truncate">{{ $customer->notes }}</flux:table.cell>
                    <flux:table.cell>{{ $customer->created_at->format('M j, Y') }}</flux:table.cell>
                    <flux:table.cell>
                        <div class="flex items-center gap-2">
                            <flux:button :href="route('customers.show', $customer)" size="sm" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                            @can('update', $customer)
                                <flux:button :href="route('customers.edit', $customer)" size="sm" variant="ghost" wire:navigate>
                                    {{ __('Edit') }}
                                </flux:button>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                        {{ $search !== '' ? __('No customers match your search.') : __('No customers yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">
        {{ $this->customers->links() }}
    </div>
</section>
