<?php

use App\Livewire\Concerns\HasProductSelection;
use App\Models\Conversation;
use App\Models\Order;
use App\Services\Orders\CreateOrder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Creates an order for a WhatsApp conversation's customer - shares its
 * product search/category filter/quantity-stepper behavior with
 * orders/create.blade.php via HasProductSelection, with one deliberate
 * difference: there is no customer_id property at all here, and never
 * has been. The customer is never chosen by the user on this page - it
 * always comes from $conversation->customer, which itself only ever
 * resolves to a conversation already proven (via route-model binding
 * through the tenant global scope, then authorize()) to belong to the
 * current restaurant.
 */
new #[Layout('components.layouts.app')] class extends Component {
    use HasProductSelection;

    public Conversation $conversation;

    public string $notes = '';

    public function mount(Conversation $conversation): void
    {
        $this->authorize('view', $conversation);

        $this->conversation = $conversation;
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
            items: $this->itemsForOrder(),
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
        <x-orders.product-selector />

        <flux:textarea wire:model="notes" label="{{ __('Notes') }}" />

        <div class="flex items-center gap-4">
            <flux:button wire:click="save" variant="primary">{{ __('Create order') }}</flux:button>
            <flux:button :href="route('conversations.show', $conversation)" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </div>
</section>
