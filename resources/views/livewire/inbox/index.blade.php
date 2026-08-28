<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
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

    #[Computed]
    public function conversations()
    {
        return Auth::user()->restaurant
            ->conversations()
            ->with(['customer', 'assignedUser'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Fired for both inbound (webhook) and outbound (sent) messages via
     * MessageCreated - either way, some conversation in this restaurant
     * needs to move/appear in the list. Nothing from the broadcast
     * payload is trusted here; the empty body just triggers Livewire to
     * re-render, which re-runs the tenant-scoped conversations() query
     * above fresh from the database (ordering, membership, and
     * everything else stays server-authoritative).
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

    <flux:table class="mt-6">
        <flux:table.columns>
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
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">
                        {{ __('No conversations yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</section>
