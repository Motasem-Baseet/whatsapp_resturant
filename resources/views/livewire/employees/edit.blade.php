<?php

use App\Models\User;
use App\Services\Employees\UpdateEmployee;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public User $employee;

    public string $name = '';
    public string $email = '';
    public string $role = 'cashier';
    public bool $is_active = true;

    public function mount(User $employee): void
    {
        $this->authorize('update', $employee);

        $this->employee = $employee;
        $this->name = $employee->name;
        $this->email = $employee->email;
        $this->role = $employee->getRoleNames()->first() ?? 'cashier';
        $this->is_active = $employee->is_active;
    }

    /**
     * Update this employee's editable fields. Re-checks authorization
     * here too, since this action runs on its own AJAX request and does
     * not re-run mount().
     */
    public function save(): void
    {
        $this->authorize('update', $this->employee);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($this->employee->id)],
            'role' => ['required', 'string', 'in:cashier,kitchen'],
            'is_active' => ['required', 'boolean'],
        ]);

        app(UpdateEmployee::class)->handle($this->employee, $validated);

        session()->flash('status', __('Employee updated.'));

        $this->redirect(route('employees.index'), navigate: true);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Edit employee') }}</flux:heading>
    <flux:subheading>{{ __('Update :name\'s account.', ['name' => $employee->name]) }}</flux:subheading>

    <form wire:submit="save" class="mt-6 flex flex-col gap-6">
        <flux:input wire:model="name" label="{{ __('Name') }}" type="text" required autofocus autocomplete="name" />

        <flux:input wire:model="email" label="{{ __('Email address') }}" type="email" required autocomplete="email" />

        <flux:select wire:model="role" label="{{ __('Role') }}">
            <flux:select.option value="cashier">{{ __('Cashier') }}</flux:select.option>
            <flux:select.option value="kitchen">{{ __('Kitchen') }}</flux:select.option>
        </flux:select>

        <flux:switch wire:model="is_active" label="{{ __('Active') }}" />

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
            <flux:button :href="route('employees.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
