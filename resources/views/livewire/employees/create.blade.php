<?php

use App\Models\User;
use App\Services\Employees\CreateEmployee;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';
    public string $role = 'cashier';

    public function mount(): void
    {
        $this->authorize('create', User::class);
    }

    /**
     * Create a cashier or kitchen employee for the current owner's own
     * restaurant. The employee role is constrained to cashier/kitchen by
     * validation, and restaurant_id is always derived from the
     * authenticated owner - neither can be influenced by the request.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
            'role' => ['required', 'string', 'in:cashier,kitchen'],
        ]);

        app(CreateEmployee::class)->handle(Auth::user(), $validated);

        session()->flash('status', __('Employee created.'));

        $this->redirect(route('employees.index'), navigate: true);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Add employee') }}</flux:heading>
    <flux:subheading>{{ __('Create a cashier or kitchen account for your restaurant.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 flex flex-col gap-6">
        <flux:input wire:model="name" label="{{ __('Name') }}" type="text" required autofocus autocomplete="name" />

        <flux:input wire:model="email" label="{{ __('Email address') }}" type="email" required autocomplete="email" />

        <flux:input wire:model="password" label="{{ __('Password') }}" type="password" required autocomplete="new-password" />

        <flux:input wire:model="password_confirmation" label="{{ __('Confirm password') }}" type="password" required autocomplete="new-password" />

        <flux:select wire:model="role" label="{{ __('Role') }}">
            <flux:select.option value="cashier">{{ __('Cashier') }}</flux:select.option>
            <flux:select.option value="kitchen">{{ __('Kitchen') }}</flux:select.option>
        </flux:select>

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Create employee') }}</flux:button>
            <flux:button :href="route('employees.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
