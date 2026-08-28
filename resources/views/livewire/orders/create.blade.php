<?php

use App\Models\Order;
use App\Services\Orders\CreateOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public string $customer_id = '';
    public string $notes = '';

    public string $selected_product_id = '';
    public int $selected_quantity = 1;

    /** @var array<int, array{product_id:int, name:string, price:string, quantity:int}> */
    public array $items = [];

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
     * Only active products in active categories may be selected - the
     * same set CreateOrder itself will independently re-validate
     * against when the order is actually created.
     */
    #[Computed]
    public function availableProducts()
    {
        return Auth::user()->restaurant
            ->products()
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    /**
     * Adds (or merges into) a line item. Prices shown here are for
     * display only - the server recalculates everything from the
     * product record when the order is actually created.
     */
    public function addItem(): void
    {
        $this->validate([
            'selected_product_id' => ['required', Rule::in($this->availableProducts->pluck('id')->all())],
            'selected_quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], ['selected_product_id' => __('product')]);

        $product = $this->availableProducts->firstWhere('id', (int) $this->selected_product_id);
        $productId = (int) $this->selected_product_id;

        if (isset($this->items[$productId])) {
            $this->items[$productId]['quantity'] += $this->selected_quantity;
        } else {
            $this->items[$productId] = [
                'product_id' => $productId,
                'name' => $product->name,
                'price' => $product->price,
                'quantity' => $this->selected_quantity,
            ];
        }

        $this->selected_product_id = '';
        $this->selected_quantity = 1;
    }

    public function removeItem(int $productId): void
    {
        unset($this->items[$productId]);
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
            items: array_map(
                fn ($item) => ['product_id' => $item['product_id'], 'quantity' => $item['quantity']],
                array_values($this->items),
            ),
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

        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Add products') }}</flux:subheading>

            <div class="mt-3 flex items-end gap-3">
                <flux:select wire:model="selected_product_id" label="{{ __('Product') }}" placeholder="{{ __('Select a product') }}" class="flex-1">
                    @foreach ($this->availableProducts as $product)
                        <flux:select.option value="{{ $product->id }}">{{ $product->name }} ({{ number_format($product->price, 2) }})</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="selected_quantity" label="{{ __('Qty') }}" type="number" min="1" max="100" class="w-24" />

                <flux:button wire:click="addItem" size="sm">{{ __('Add') }}</flux:button>
            </div>
            @error('items') <p class="mt-2 text-sm text-red-600">{{ $message }}</p> @enderror

            @if (! empty($items))
                <flux:table class="mt-4">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Product') }}</flux:table.column>
                        <flux:table.column>{{ __('Qty') }}</flux:table.column>
                        <flux:table.column>{{ __('Est. line total') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($items as $productId => $item)
                            <flux:table.row wire:key="item-{{ $productId }}">
                                <flux:table.cell>{{ $item['name'] }}</flux:table.cell>
                                <flux:table.cell>{{ $item['quantity'] }}</flux:table.cell>
                                <flux:table.cell>{{ number_format($item['price'] * $item['quantity'], 2) }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:button wire:click="removeItem({{ $productId }})" size="sm" variant="ghost">
                                        {{ __('Remove') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>

        <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />

        <div class="flex items-center gap-4">
            <flux:button wire:click="save" variant="primary">{{ __('Create order') }}</flux:button>
            <flux:button :href="route('orders.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </div>
</section>
