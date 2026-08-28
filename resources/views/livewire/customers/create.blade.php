<?php

use App\Models\Customer;
use App\Services\Customers\CreateCustomer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $name = '';
    public string $phone = '';
    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('create', Customer::class);
    }

    /**
     * Create a customer for the current owner's own restaurant.
     * restaurant_id is never accepted here - it is assigned server-side
     * from the current owner's own restaurant.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:30',
                'regex:/^[0-9+\-\s()]+$/',
                Rule::unique('customers', 'phone')->where('restaurant_id', Auth::user()->restaurant_id),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        app(CreateCustomer::class)->handle(Auth::user()->restaurant, $validated);

        session()->flash('status', __('Customer created.'));

        $this->redirect(route('customers.index'), navigate: true);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Add customer') }}</flux:heading>
    <flux:subheading>{{ __('Create a new customer record for your restaurant.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 flex flex-col gap-6">
        <flux:input wire:model="name" label="{{ __('Name') }}" type="text" required autofocus />

        <flux:input wire:model="phone" label="{{ __('Phone') }}" type="text" required placeholder="+962790000000" />

        <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Create customer') }}</flux:button>
            <flux:button :href="route('customers.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
