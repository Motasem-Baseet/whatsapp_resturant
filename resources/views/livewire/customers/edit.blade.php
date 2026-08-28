<?php

use App\Models\Customer;
use App\Services\Customers\UpdateCustomer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Customer $customer;

    public string $name = '';
    public string $phone = '';
    public string $notes = '';

    public function mount(Customer $customer): void
    {
        $this->authorize('update', $customer);

        $this->customer = $customer;
        $this->name = $customer->name;
        $this->phone = $customer->phone;
        $this->notes = (string) $customer->notes;
    }

    /**
     * Update this customer. Re-checks authorization here too, since this
     * action runs on its own AJAX request and does not re-run mount().
     * restaurant_id is never part of the validated data, so it can
     * never change through this form.
     */
    public function save(): void
    {
        $this->authorize('update', $this->customer);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:30',
                'regex:/^[0-9+\-\s()]+$/',
                Rule::unique('customers', 'phone')
                    ->where('restaurant_id', Auth::user()->restaurant_id)
                    ->ignore($this->customer->id),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        app(UpdateCustomer::class)->handle($this->customer, $validated);

        session()->flash('status', __('Customer updated.'));

        $this->redirect(route('customers.index'), navigate: true);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Edit customer') }}</flux:heading>
    <flux:subheading>{{ __('Update this customer record.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 flex flex-col gap-6">
        <flux:input wire:model="name" label="{{ __('Name') }}" type="text" required autofocus />

        <flux:input wire:model="phone" label="{{ __('Phone') }}" type="text" required />

        <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
            <flux:button :href="route('customers.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
