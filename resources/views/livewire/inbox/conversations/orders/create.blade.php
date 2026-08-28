<?php

use App\Models\Conversation;
use App\Models\Order;
use App\Services\Orders\CreateOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Creates an order for a WhatsApp conversation's customer - mirrors
 * resources/views/livewire/orders/create.blade.php closely (same item
 * collection shape, same "prices shown are estimates only" approach),
 * with one deliberate difference: there is no customer_id property at
 * all here. The customer is never chosen by the user on this page - it
 * always comes from $conversation->customer, which itself only ever
 * resolves to a conversation already proven (via route-model binding
 * through the tenant global scope, then authorize()) to belong to the
 * current restaurant.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public Conversation $conversation;

    public string $notes = '';

    public string $selected_product_id = '';
    public int $selected_quantity = 1;

    /** @var array<int, array{product_id:int, name:string, price:string, quantity:int}> */
    public array $items = [];

    public function mount(Conversation $conversation): void
    {
        $this->authorize('view', $conversation);

        $this->conversation = $conversation;
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
     * Adds (or merges into) a line item. The price stored here is for
     * display only (an estimated running total on this page) - the
     * server recalculates everything from the product record when the
     * order is actually created, so a forged value here changes nothing
     * about what gets charged.
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
     * Create the order. Re-authorizes both the conversation (mount()
     * does not re-run on this action - a since-revoked or
     * since-reassigned user must not still be able to create an order
     * here) and order creation itself, matching the direct
     * orders.create page's own authorize('create', Order::class) check.
     *
     * restaurant_id, customer_id, conversation_id, created_by, and every
     * money value are never accepted as input here - CreateOrder derives
     * the customer from $conversation->customer, the restaurant and
     * creator from the authenticated user, and recalculates every price
     * from the current product records.
     */
    public function save(): void
    {
        $this->authorize('update', $this->conversation);
        $this->authorize('create', Order::class);

        $validated = $this->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (empty($this->items)) {
            $this->addError('items', __('Add at least one product to the order.'));

            return;
        }

        $order = app(CreateOrder::class)->handle(
            restaurant: Auth::user()->restaurant,
            customer: $this->conversation->customer,
            items: array_map(
                fn ($item) => ['product_id' => $item['product_id'], 'quantity' => $item['quantity']],
                array_values($this->items),
            ),
            conversation: $this->conversation,
            createdBy: Auth::user(),
            notes: $validated['notes'] ?: null,
        );

        $this->redirect(route('orders.show', $order), navigate: true);
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('New order for :name', ['name' => $conversation->customer->name]) }}</flux:heading>
            <flux:subheading>{{ $conversation->customer->phone }}</flux:subheading>
        </div>

        <flux:button :href="route('conversations.show', $conversation)" variant="ghost" wire:navigate>
            {{ __('Back to conversation') }}
        </flux:button>
    </div>

    <div class="mt-6 flex flex-col gap-6">
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
            <flux:button :href="route('conversations.show', $conversation)" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </div>
</section>
