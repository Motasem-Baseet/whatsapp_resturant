<?php

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use App\Services\Orders\UpdateOrderStatus;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Order $order;

    public function mount(Order $order): void
    {
        $this->authorize('viewAsKitchen', $order);

        // Historical product_name/quantity snapshots come straight off
        // OrderItem - never from the live Product record - so this page
        // reflects what was actually ordered even if a product has
        // since changed or been removed.
        $this->order = $order->load(['customer', 'conversation', 'items']);
    }

    /**
     * The single next step the kitchen interface offers as a button, if
     * any - confirmed -> preparing, or preparing -> ready. This is only
     * used to decide what to render; transitionTo() below independently
     * re-derives and re-checks the same thing server-side rather than
     * trusting that only this button could have been clicked.
     */
    #[Computed]
    public function nextStatus(): ?OrderStatus
    {
        return match ($this->order->status) {
            OrderStatus::Confirmed => OrderStatus::Preparing,
            OrderStatus::Preparing => OrderStatus::Ready,
            default => null,
        };
    }

    /**
     * Transition the order to the given status. Mirrors the parameterised
     * shape of the owner/cashier order page's transitionTo(), rather
     * than an implicit no-argument "advance" - so a forged/direct call
     * for a status the kitchen role is not allowed to set (e.g.
     * "cancelled" or "completed") is independently rejected here, not
     * merely absent from the rendered buttons.
     *
     * Order of checks, before ever touching the domain service:
     * 1. Re-authorize tenant ownership + kitchen role (viewAsKitchen).
     * 2. Re-authorize the specific transition is kitchen-allowed
     *    (canTransitionAsKitchen) - confirmed->preparing or
     *    preparing->ready only, regardless of what OrderStatus itself
     *    would otherwise allow (e.g. cancellation).
     * 3. Only then delegate to the existing, shared UpdateOrderStatus
     *    service - the same one owner/cashier use - so the domain
     *    transition rules are never duplicated here.
     */
    public function transitionTo(string $status): void
    {
        $this->authorize('viewAsKitchen', $this->order);

        $target = OrderStatus::tryFrom($status);

        if ($target === null) {
            $this->addError('status', __('Unknown status.'));

            return;
        }

        $this->authorize('canTransitionAsKitchen', [$this->order, $target]);

        try {
            app(UpdateOrderStatus::class)->handle($this->order, $target, Auth::user());
        } catch (InvalidOrderStatusTransitionException $e) {
            $this->addError('status', $e->getMessage());

            return;
        }

        $this->order->refresh();

        session()->flash('status', __('Order status updated.'));
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Order #:id', ['id' => $order->id]) }}</flux:heading>
            <flux:subheading>{{ $order->created_at->format('M j, Y g:i A') }}</flux:subheading>
        </div>

        <flux:button :href="route('kitchen.orders.index')" variant="ghost" wire:navigate>{{ __('Back to kitchen') }}</flux:button>
    </div>

    @error('status') <p class="mt-4 text-sm text-red-600">{{ $message }}</p> @enderror

    <div class="mt-6 grid gap-6 md:grid-cols-2">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Customer') }}</flux:subheading>
            <p class="mt-1 font-medium">{{ $order->customer->name }}</p>
            <p class="text-sm text-zinc-500">{{ $order->customer->phone }}</p>

            @if ($order->conversation)
                <div class="mt-3">
                    <flux:badge size="sm">{{ __('Linked to a conversation') }}</flux:badge>
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Status') }}</flux:subheading>
            <div class="mt-1">
                <flux:badge>{{ $order->status->label() }}</flux:badge>
            </div>

            @if ($this->nextStatus())
                <div class="mt-3">
                    <flux:button wire:click="transitionTo('{{ $this->nextStatus()->value }}')" size="sm" variant="primary">
                        {{ __('Mark :status', ['status' => $this->nextStatus()->label()]) }}
                    </flux:button>
                </div>
            @endif
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:subheading>{{ __('Items') }}</flux:subheading>

        <flux:table class="mt-3">
            <flux:table.columns>
                <flux:table.column>{{ __('Product') }}</flux:table.column>
                <flux:table.column>{{ __('Quantity') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($order->items as $item)
                    <flux:table.row wire:key="kitchen-item-{{ $item->id }}">
                        <flux:table.cell>{{ $item->product_name }}</flux:table.cell>
                        <flux:table.cell>{{ $item->quantity }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
