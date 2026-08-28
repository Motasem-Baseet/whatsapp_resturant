<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $role = '';
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRole(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * role/status are validated against a fixed allowlist before ever
     * reaching the query - a forged Livewire request setting either to
     * an arbitrary string just falls back to "no filter" rather than
     * being trusted directly in a where() clause.
     */
    protected function validRole(): ?string
    {
        return in_array($this->role, ['cashier', 'kitchen'], true) ? $this->role : null;
    }

    protected function validStatus(): ?string
    {
        return in_array($this->status, ['active', 'inactive'], true) ? $this->status : null;
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
        $role = $this->validRole();
        $status = $this->validStatus();

        return Auth::user()->restaurant
            ->users()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['cashier', 'kitchen']))
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    $query->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%');
                });
            })
            ->when($role !== null, fn ($query) => $query->whereHas('roles', fn ($q) => $q->where('name', $role)))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(15);
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

    <div class="mt-6 flex flex-wrap items-end gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            type="search"
            label="{{ __('Search') }}"
            placeholder="{{ __('Search by name or email...') }}"
            class="max-w-sm"
        />

        <flux:select wire:model.live="role" label="{{ __('Role') }}" placeholder="{{ __('All roles') }}" class="w-44">
            <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
            <flux:select.option value="cashier">{{ __('Cashier') }}</flux:select.option>
            <flux:select.option value="kitchen">{{ __('Kitchen') }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="status" label="{{ __('Status') }}" placeholder="{{ __('All statuses') }}" class="w-44">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Added') }}</flux:table.column>
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
                    <flux:table.cell>{{ $employee->created_at->format('M j, Y') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button :href="route('employees.edit', $employee)" size="sm" wire:navigate>
                            {{ __('Edit') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">
                        {{ __('No employees match these filters.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">
        {{ $this->employees->links() }}
    </div>
</section>
