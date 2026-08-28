<?php

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    /**
     * Three independent, explicit filter dimensions rather than a pile
     * of unrelated booleans. Each is a plain string bound via
     * wire:model.live - because Livewire hydrates public properties
     * directly from client-sent request state, a forged request could
     * set these to anything, so every read of them goes through the
     * matching validXxxFilter() whitelist below rather than being used
     * directly in a query.
     */
    public string $assignmentFilter = 'all';
    public string $readFilter = 'all';
    public string $statusFilter = 'all';

    public function mount(): void
    {
        $this->authorize('viewAny', Conversation::class);
    }

    /**
     * Used only to address this user's own restaurant's broadcast
     * channel below - not bound to any input, so there is no path for
     * a client to make this resolve to a different restaurant. Even if
     * there were, the channel authorization in routes/channels.php
     * independently re-checks the real authenticated user's own
     * restaurant_id before allowing the subscription.
     */
    #[Computed]
    public function restaurantId(): int
    {
        return Auth::user()->restaurant_id;
    }

    protected function validAssignmentFilter(): string
    {
        return in_array($this->assignmentFilter, ['all', 'mine', 'unassigned', 'assigned'], true)
            ? $this->assignmentFilter
            : 'all';
    }

    protected function validReadFilter(): string
    {
        return in_array($this->readFilter, ['all', 'unread'], true)
            ? $this->readFilter
            : 'all';
    }

    protected function validStatusFilter(): string
    {
        return in_array($this->statusFilter, ['all', 'open', 'closed'], true)
            ? $this->statusFilter
            : 'all';
    }

    #[Computed]
    public function conversations()
    {
        $user = Auth::user();

        $query = $user->restaurant
            ->conversations()
            ->with(['customer', 'assignedUser']);

        match ($this->validAssignmentFilter()) {
            'mine' => $query->where('assigned_user_id', $user->id),
            'unassigned' => $query->whereNull('assigned_user_id'),
            'assigned' => $query->whereNotNull('assigned_user_id'),
            default => null,
        };

        match ($this->validStatusFilter()) {
            'open' => $query->where('status', ConversationStatus::Open),
            'closed' => $query->where('status', ConversationStatus::Closed),
            default => null,
        };

        if ($this->validReadFilter() === 'unread') {
            $query->unreadFor($user);
        }

        return $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * IDs of conversations unread for the current user, computed
     * independently of the (possibly differently-filtered)
     * conversations() list above, so the per-row unread indicator is
     * correct no matter which filter is currently active.
     */
    #[Computed]
    public function unreadConversationIds()
    {
        return Auth::user()->restaurant
            ->conversations()
            ->unreadFor(Auth::user())
            ->pluck('conversations.id')
            ->all();
    }

    /**
     * Fired for both inbound (webhook) and outbound (sent) messages via
     * MessageCreated - either way, some conversation in this restaurant
     * needs to move/appear in the list, and unread state may have
     * changed. Nothing from the broadcast payload is trusted here; the
     * empty body just triggers Livewire to re-render, which re-runs the
     * tenant-scoped conversations() and unreadConversationIds() queries
     * above fresh from the database.
     */
    #[On('echo-private:restaurants.{restaurantId}.inbox,.message.created')]
    public function onMessageCreated(): void
    {
        //
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('Inbox') }}</flux:heading>
            <flux:subheading>{{ __('Conversations with your customers.') }}</flux:subheading>
        </div>

        <flux:button :href="route('conversations.create')" variant="primary" wire:navigate>
            {{ __('New conversation') }}
        </flux:button>
    </div>

    <div class="mt-6 flex flex-wrap items-end gap-4">
        <flux:select wire:model.live="assignmentFilter" label="{{ __('Assignment') }}" class="max-w-xs">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="mine">{{ __('Mine') }}</flux:select.option>
            <flux:select.option value="unassigned">{{ __('Unassigned') }}</flux:select.option>
            <flux:select.option value="assigned">{{ __('Assigned') }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="statusFilter" label="{{ __('Status') }}" class="max-w-xs">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="open">{{ __('Open') }}</flux:select.option>
            <flux:select.option value="closed">{{ __('Closed') }}</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="readFilter" label="{{ __('Read state') }}" class="max-w-xs">
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
            <flux:select.option value="unread">{{ __('Unread') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table class="mt-6">
        <flux:table.columns>
            <flux:table.column></flux:table.column>
            <flux:table.column>{{ __('Customer') }}</flux:table.column>
            <flux:table.column>{{ __('Phone') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Assigned to') }}</flux:table.column>
            <flux:table.column>{{ __('Last message') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->conversations as $conversation)
                <flux:table.row wire:key="conversation-{{ $conversation->id }}">
                    <flux:table.cell>
                        @if (in_array($conversation->id, $this->unreadConversationIds, true))
                            <span class="inline-block h-2 w-2 rounded-full bg-blue-500" title="{{ __('Unread') }}"></span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $conversation->customer->name }}</flux:table.cell>
                    <flux:table.cell>{{ $conversation->customer->phone }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($conversation->status === \App\Enums\ConversationStatus::Open)
                            <flux:badge color="green" size="sm">{{ __('Open') }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('Closed') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $conversation->assignedUser?->name ?? __('Unassigned') }}</flux:table.cell>
                    <flux:table.cell>{{ $conversation->last_message_at?->diffForHumans() ?? __('No messages yet') }}</flux:table.cell>
                    <flux:table.cell>
                        <flux:button :href="route('conversations.show', $conversation)" size="sm" wire:navigate>
                            {{ __('Open') }}
                        </flux:button>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="7" class="text-center text-zinc-500">
                        {{ __('No conversations match these filters.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
