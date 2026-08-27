<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    /**
     * Cashier and kitchen staff belonging to the current owner's own
     * restaurant. The owner themselves is never listed here.
     *
     * Uses whereHas() rather than Spatie's role() scope deliberately:
     * role() throws if a named role doesn't exist yet in the database,
     * which would 500 this page in the (recoverable) case of an owner
     * with no employees visiting before the cashier/kitchen roles have
     * ever been assigned to anyone.
     */
    #[Computed]
    public function employees()
    {
        return Auth::user()->restaurant
            ->users()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['cashier', 'kitchen']))
            ->orderBy('name')
            ->get();
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Employees') }}</flux:heading>
            <flux:subheading>{{ __('Manage the cashier and kitchen staff for your restaurant.') }}</flux:subheading>
        </div>

        <flux:button :href="route('employees.create')" variant="primary" wire:navigate>
            {{ __('Add employee') }}
        </flux:button>
    </div>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->employees as $employee)
                <flux:table.row wire:key="employee-{{ $employee->id }}">
                    <flux:table.cell>{{ $employee->name }}</flux:table.cell>
                    <flux:table.cell>{{ $employee->email }}</flux:table.cell>
                    <flux:table.cell class="capitalize">{{ $employee->getRoleNames()->first() }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($employee->is_active)
                            <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:button :href="route('employees.edit', $employee)" size="sm" wire:navigate>
                            {{ __('Edit') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">
                        {{ __('No employees yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
