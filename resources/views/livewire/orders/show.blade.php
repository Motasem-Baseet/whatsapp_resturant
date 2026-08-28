<?php

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use App\Services\Orders\UpdateOrderStatus;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Order $order;

    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load(['customer', 'conversation', 'createdBy', 'items']);
    }

    /**
     * Only the statuses OrderStatus itself allows from the current
     * status are ever offered - the server is still the final
     * authority via UpdateOrderStatus, this is just so the UI never
     * even shows an illegal option.
     */
    public function transitionTo(string $status): void
    {
        $this->authorize('update', $this->order);

        try {
            app(UpdateOrderStatus::class)->handle($this->order, OrderStatus::from($status), Auth::user());
        } catch (InvalidOrderStatusTransitionException $e) {
            $this->addError('status', $e->getMessage());

            return;
        }

        $this->order->refresh();

        session()->flash('status', __('Order status updated.'));
    }

    /**
     * Recomputed on every render (rather than loaded once in mount())
     * so it stays current after transitionTo() records a new row,
     * without needing a separate manual reload. Ordered oldest-first so
     * the timeline reads top-to-bottom in the order events actually
     * happened; created_at is the primary sort with id as a tiebreaker
     * for transitions recorded within the same second.
     */
    #[Computed]
    public function statusHistory()
    {
        return $this->order->statusHistory()
            ->with('changedBy')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Fired on every successful transition, from either the owner/
     * cashier or kitchen entry point (both go through the same
     * UpdateOrderStatus service). Only reacts when the event names the
     * order currently open on this page - an update to some other
     * order in the same restaurant is silently ignored, matching
     * inbox/conversations/show.blade.php's own
     * onMessageCreated()/onMessageStatusUpdated() reasoning.
     *
     * The event payload itself is never trusted as data - it is only a
     * signal to re-query. The order (and therefore the status and
     * available transitions) is reloaded straight from the database,
     * which remains the sole source of truth.
     */
    #[On('echo-private:restaurants.{order.restaurant_id}.orders,.order.status-updated')]
    public function onOrderStatusUpdated(array $event): void
    {
        if ((int) ($event['id'] ?? 0) !== $this->order->id) {
            return;
        }

        $this->order->refresh();
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Order #:id', ['id' => $order->id]) }}</flux:heading>
            <flux:subheading>{{ $order->created_at->format('M j, Y g:i A') }}</flux:subheading>
        </div>

        <flux:button :href="route('orders.index')" variant="ghost" wire:navigate>{{ __('Back to orders') }}</flux:button>
    </div>

    @error('status') <p class="mt-4 text-sm text-red-600">{{ $message }}</p> @enderror

    <div class="mt-6 grid gap-6 md:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Customer') }}</flux:subheading>
            <p class="mt-1 font-medium">{{ $order->customer->name }}</p>
            <p class="text-sm text-zinc-500">{{ $order->customer->phone }}</p>

            @if ($order->conversation)
                <div class="mt-3">
                    <flux:button :href="route('conversations.show', $order->conversation)" size="sm" wire:navigate>
                        {{ __('View conversation') }}
                    </flux:button>
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Status') }}</flux:subheading>
            <div class="mt-1">
                <flux:badge>{{ $order->status->label() }}</flux:badge>
            </div>

            @if (! empty($order->status->allowedTransitions()))
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($order->status->allowedTransitions() as $nextStatus)
                        <flux:button wire:click="transitionTo('{{ $nextStatus->value }}')" size="sm">
                            {{ __('Mark :status', ['status' => $nextStatus->label()]) }}
                        </flux:button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Created by') }}</flux:subheading>
            <p class="mt-1">{{ $order->createdBy?->name ?? __('Unknown') }}</p>
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:subheading>{{ __('Items') }}</flux:subheading>

        <flux:table class="mt-3">
            <flux:table.columns>
                <flux:table.column>{{ __('Product') }}</flux:table.column>
                <flux:table.column>{{ __('Unit price') }}</flux:table.column>
                <flux:table.column>{{ __('Quantity') }}</flux:table.column>
                <flux:table.column>{{ __('Line total') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($order->items as $item)
                    <flux:table.row wire:key="item-{{ $item->id }}">
                        <flux:table.cell>{{ $item->product_name }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($item->unit_price, 2) }}</flux:table.cell>
                        <flux:table.cell>{{ $item->quantity }}</flux:table.cell>
                        <flux:table.cell>{{ number_format($item->line_total, 2) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        <div class="mt-4 flex flex-col items-end gap-1 text-sm">
            <div class="flex w-48 justify-between">
                <span class="text-zinc-500">{{ __('Subtotal') }}</span>
                <span>{{ number_format($order->subtotal, 2) }}</span>
            </div>
            <div class="flex w-48 justify-between font-semibold">
                <span>{{ __('Total') }}</span>
                <span>{{ number_format($order->total, 2) }}</span>
            </div>
        </div>

        @if ($order->notes)
            <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-neutral-700">
                <flux:subheading>{{ __('Notes') }}</flux:subheading>
                <p class="mt-1 text-sm">{{ $order->notes }}</p>
            </div>
        @endif
    </div>

    <div class="mt-6 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:subheading>{{ __('Status timeline') }}</flux:subheading>

        <ol class="mt-4 flex flex-col gap-4 border-l-2 border-neutral-200 pl-4 dark:border-neutral-700">
            {{-- The order's original status is always Pending (see
                 CreateOrder) but order_status_histories only records
                 transitions, not the creation itself - this bookend is
                 rendered directly from the order's own created_at, never
                 written to the database, so it can never be mistaken for
                 a real audit row. --}}
            <li>
                <flux:badge size="sm">{{ OrderStatus::Pending->label() }}</flux:badge>
                <p class="mt-1 text-sm font-medium">{{ __('Order created') }}</p>
                <p class="text-sm text-zinc-500">{{ $order->created_at->format('M j, Y g:i A') }}</p>
            </li>

            @foreach ($this->statusHistory as $entry)
                <li>
                    <flux:badge size="sm">{{ OrderStatus::from($entry->to_status)->label() }}</flux:badge>
                    <p class="mt-1 text-sm font-medium">
                        {{ __(':from → :to', ['from' => OrderStatus::from($entry->from_status)->label(), 'to' => OrderStatus::from($entry->to_status)->label()]) }}
                    </p>
                    <p class="text-sm text-zinc-500">
                        {{ __('by :name', ['name' => $entry->changedBy?->name ?? __('Unknown')]) }}
                        &middot; {{ $entry->created_at->format('M j, Y g:i A') }}
                    </p>
                </li>
            @endforeach
        </ol>
    </div>
</section>
