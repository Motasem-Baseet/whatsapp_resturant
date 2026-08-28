<?php

use App\Livewire\Concerns\HasProductSelection;
use App\Models\Order;
use App\Services\Orders\CreateOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    use HasProductSelection;

    public string $customer_id = '';
    public string $notes = '';

    public function mount(): void
    {
        $this->authorize('create', Order::class);
    }

    #[Computed]
    public function customers()
    {
        return Auth::user()->restaurant->customers()->orderBy('name')->get();
    }

    /**
     * Create the order. restaurant_id, product prices, line totals,
     * subtotal, and total are never accepted here - CreateOrder
     * calculates all of that server-side from the current product
     * records.
     */
    public function save(): void
    {
        $this->authorize('create', Order::class);

        $validated = $this->validate([
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('restaurant_id', Auth::user()->restaurant_id),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (empty($this->items)) {
            $this->addError('items', __('Add at least one product to the order.'));

            return;
        }

        $customer = Auth::user()->restaurant->customers()->findOrFail($validated['customer_id']);

        $order = app(CreateOrder::class)->handle(
            restaurant: Auth::user()->restaurant,
            customer: $customer,
            items: $this->itemsForOrder(),
            createdBy: Auth::user(),
            notes: $validated['notes'] ?: null,
        );

        $this->redirect(route('orders.show', $order), navigate: true);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('New order') }}</flux:heading>
    <flux:subheading>{{ __('Create an order for an existing customer.') }}</flux:subheading>

    <div class="mt-6 flex flex-col gap-6">
        <flux:select wire:model="customer_id" label="{{ __('Customer') }}" placeholder="{{ __('Select a customer') }}">
            @foreach ($this->customers as $customer)
                <flux:select.option value="{{ $customer->id }}">{{ $customer->name }} ({{ $customer->phone }})</flux:select.option>
            @endforeach
        </flux:select>

        <x-orders.product-selector />

        <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />

        <div class="flex items-center gap-4">
            <flux:button wire:click="save" variant="primary">{{ __('Create order') }}</flux:button>
            <flux:button :href="route('orders.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </div>
</section>
