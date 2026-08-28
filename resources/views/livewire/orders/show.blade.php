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

    /**
     * Bound only to the cancellation modal's textarea - never
     * mass-assigned onto the order (Order::$fillable deliberately
     * excludes cancellation_reason), only ever passed explicitly as a
     * typed argument to UpdateOrderStatus::handle() below.
     */
    public string $cancellationReason = '';

    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        // statusHistory.changedBy is eager-loaded once here (rather than
        // statusHistory() below issuing its own query) so it doubles as
        // the source for both the timeline display and
        // Order::currentStatusStartedAt()/requiresAttention()/
        // attentionReason() - see those methods' docblocks. refresh()
        // (used by transitionTo()/onOrderStatusUpdated() below) reloads
        // whatever relations are already loaded, so this stays correct
        // after both a transition and a real-time update.
        $this->order = $order->load(['customer', 'conversation', 'createdBy', 'items', 'statusHistory.changedBy']);
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
     * The confirmed-cancellation path, invoked only from the cancel
     * modal's form. Deliberately separate from transitionTo() rather
     * than folding a reason parameter into it, so the generic
     * transition action's signature stays untouched for every other
     * status (and every existing test calling it). Re-authorizes and
     * re-validates independently of whatever the modal displayed,
     * since the modal itself is UX only, not the security boundary.
     */
    public function cancelOrder(): void
    {
        $this->authorize('update', $this->order);

        $validated = $this->validate([
            'cancellationReason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            app(UpdateOrderStatus::class)->handle(
                $this->order,
                OrderStatus::Cancelled,
                Auth::user(),
                $validated['cancellationReason'] !== '' ? $validated['cancellationReason'] : null,
            );
        } catch (InvalidOrderStatusTransitionException $e) {
            $this->addError('status', $e->getMessage());

            return;
        }

        $this->order->refresh();
        $this->cancellationReason = '';
        $this->modal('cancel-order')->close();

        session()->flash('status', __('Order cancelled.'));
    }

    /**
     * Reads from the statusHistory.changedBy relation eager-loaded in
     * mount() (and reloaded by refresh() after a transition or a
     * matching real-time event) rather than issuing a second query -
     * the same collection Order::currentStatusStartedAt() uses.
     * Ordered oldest-first so the timeline reads top-to-bottom in the
     * order events actually happened; created_at is the primary sort
     * with id as a tiebreaker for transitions recorded within the same
     * second.
     */
    #[Computed]
    public function statusHistory()
    {
        return $this->order->statusHistory
            ->sortBy([['created_at', 'asc'], ['id', 'asc']])
            ->values();
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
            <div class="mt-1 flex items-center gap-2">
                <flux:badge>{{ $order->status->label() }}</flux:badge>
                @if ($order->requiresAttention())
                    <flux:badge color="red" size="sm">{{ __('Needs attention') }}</flux:badge>
                @endif
            </div>
            <p class="mt-1 text-sm text-zinc-500">
                {{ __('In this status for :duration', ['duration' => $order->currentStatusStartedAt()->diffForHumans(null, true)]) }}
            </p>
            @if ($order->attentionMessage())
                <p class="mt-1 text-sm text-red-600">{{ __($order->attentionMessage()) }}</p>
            @endif

            @if ($order->status === OrderStatus::Cancelled)
                <div class="mt-3 rounded-lg bg-red-50 p-3 text-sm dark:bg-red-950">
                    <p class="font-medium text-red-700 dark:text-red-400">{{ __('This order was cancelled and cannot be reopened.') }}</p>
                    @if ($order->cancellation_reason)
                        <p class="mt-1 text-zinc-600 dark:text-zinc-400">{{ __('Reason: :reason', ['reason' => $order->cancellation_reason]) }}</p>
                    @endif
                </div>
            @elseif ($order->status === OrderStatus::Completed)
                <p class="mt-3 text-sm text-zinc-500">{{ __('This order is complete. No further action is needed.') }}</p>
            @elseif (! empty($order->status->allowedTransitions()))
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($order->status->allowedTransitions() as $nextStatus)
                        @if ($nextStatus === OrderStatus::Cancelled)
                            <flux:modal.trigger name="cancel-order">
                                <flux:button size="sm" variant="danger" x-data="" x-on:click.prevent="$dispatch('open-modal', 'cancel-order')">
                                    {{ __('Cancel order') }}
                                </flux:button>
                            </flux:modal.trigger>
                        @else
                            <flux:button wire:click="transitionTo('{{ $nextStatus->value }}')" size="sm" variant="{{ $nextStatus === OrderStatus::Completed ? 'primary' : 'filled' }}">
                                {{ __('Mark :status', ['status' => $nextStatus->label()]) }}
                            </flux:button>
                        @endif
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

    <flux:modal name="cancel-order" :show="$errors->has('status') || $errors->has('cancellationReason')" focusable class="max-w-lg">
        <form wire:submit="cancelOrder" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Cancel this order?') }}</flux:heading>
                <flux:subheading>
                    {{ __('This is a terminal action - once cancelled, this order can never return to the normal lifecycle.') }}
                </flux:subheading>
            </div>

            @error('status') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <flux:textarea wire:model="cancellationReason" label="{{ __('Reason (optional)') }}" rows="3" maxlength="1000" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Close') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" type="submit">{{ __('Confirm cancellation') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
