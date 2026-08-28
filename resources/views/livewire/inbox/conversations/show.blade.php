<?php

use App\Enums\ConversationStatus;
use App\Exceptions\WhatsAppMessageSendException;
use App\Models\Conversation;
use App\Models\WhatsAppAccount;
use App\Services\Inbox\AssignConversation;
use App\Services\WhatsApp\SendWhatsAppMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Conversation $conversation;

    public string $assigned_user_id = '';
    public string $message_content = '';

    public function mount(Conversation $conversation): void
    {
        $this->authorize('view', $conversation);

        $this->conversation = $conversation;
        $this->assigned_user_id = $conversation->assigned_user_id ? (string) $conversation->assigned_user_id : '';
    }

    #[Computed]
    public function messages()
    {
        return $this->conversation->messages()->orderBy('created_at')->get();
    }

    /**
     * Owner and cashier users are eligible for assignment; kitchen is
     * not. Uses whereHas() rather than Spatie's role() scope
     * deliberately - role() throws if a named role doesn't exist yet in
     * the database, which would break this page on a fresh restaurant
     * that has never had a cashier.
     */
    #[Computed]
    public function assignableUsers()
    {
        return Auth::user()->restaurant
            ->users()
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['owner', 'cashier']))
            ->orderBy('name')
            ->get();
    }

    /**
     * Assign (or unassign) this conversation. The assignee is resolved
     * from the current restaurant's own eligible users only - never
     * trusted as a raw id - and AssignConversation re-validates
     * restaurant/role eligibility itself regardless.
     */
    public function assign(): void
    {
        $this->authorize('update', $this->conversation);

        $validated = $this->validate([
            'assigned_user_id' => [
                'nullable',
                Rule::exists('users', 'id')->where('restaurant_id', Auth::user()->restaurant_id),
            ],
        ]);

        $assignee = null;

        if ($validated['assigned_user_id']) {
            $assignee = Auth::user()->restaurant
                ->users()
                ->whereHas('roles', fn ($query) => $query->whereIn('name', ['owner', 'cashier']))
                ->find($validated['assigned_user_id']);

            if (! $assignee) {
                $this->addError('assigned_user_id', __('Only owner or cashier users may be assigned.'));

                return;
            }
        }

        app(AssignConversation::class)->handle($this->conversation, $assignee);

        $this->conversation->refresh();

        session()->flash('status', __('Conversation updated.'));
    }

    public function toggleStatus(): void
    {
        $this->authorize('update', $this->conversation);

        $this->conversation->status = $this->conversation->status === ConversationStatus::Open
            ? ConversationStatus::Closed
            : ConversationStatus::Open;
        $this->conversation->save();
    }

    /**
     * Send a real WhatsApp text message to this conversation's customer.
     *
     * Re-authorizes here rather than trusting mount()-time authorization -
     * a long-lived page session must not let a since-revoked or
     * since-reassigned user keep sending. The WhatsAppAccount is resolved
     * from the conversation's own restaurant only; the client controls
     * nothing but the message text.
     */
    public function sendMessage(): void
    {
        $this->authorize('update', $this->conversation);

        $validated = $this->validate([
            'message_content' => ['required', 'string', 'max:2000'],
        ]);

        $account = WhatsAppAccount::query()
            ->where('restaurant_id', $this->conversation->restaurant_id)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            $this->addError('message_content', __('No active WhatsApp account is configured for this restaurant.'));

            return;
        }

        try {
            app(SendWhatsAppMessage::class)->handle($account, $this->conversation, Auth::user(), $validated['message_content']);
        } catch (WhatsAppMessageSendException $e) {
            $this->addError('message_content', $e->getMessage());

            return;
        }

        $this->reset('message_content');
        $this->conversation->refresh();
        session()->flash('status', __('Message sent.'));
    }

    /**
     * Fired by MessageCreated on this restaurant's inbox channel - could
     * be for any conversation in the restaurant, so it is ignored unless
     * it matches the conversation currently being viewed. When it does
     * match, no client data is trusted or appended directly: the empty
     * body just triggers a re-render, and messages() (a Computed
     * property, re-evaluated fresh on every request) re-queries the
     * conversation's messages from the database - which is what
     * actually shows the new message and makes replayed/duplicate
     * events naturally harmless, since re-querying the same DB state
     * twice produces the same result both times.
     */
    #[On('echo-private:restaurants.{conversation.restaurant_id}.inbox,.message.created')]
    public function onMessageCreated(array $event): void
    {
        if ((int) ($event['conversation_id'] ?? 0) !== $this->conversation->id) {
            return;
        }
    }

    /**
     * Same reasoning as onMessageCreated() - only re-renders (picking up
     * the updated status via a fresh messages() query) when the event
     * belongs to the conversation currently on screen.
     */
    #[On('echo-private:restaurants.{conversation.restaurant_id}.inbox,.message.status-updated')]
    public function onMessageStatusUpdated(array $event): void
    {
        if ((int) ($event['conversation_id'] ?? 0) !== $this->conversation->id) {
            return;
        }
    }
}; ?>

<section class="w-full">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ $conversation->customer->name }}</flux:heading>
            <flux:subheading>{{ $conversation->customer->phone }}</flux:subheading>
        </div>

        <flux:button :href="route('inbox.index')" variant="ghost" wire:navigate>{{ __('Back to inbox') }}</flux:button>
    </div>

    <div class="mt-6 grid gap-6 md:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Status') }}</flux:subheading>
            <div class="mt-2 flex items-center gap-3">
                @if ($conversation->status === \App\Enums\ConversationStatus::Open)
                    <flux:badge color="green" size="sm">{{ __('Open') }}</flux:badge>
                @else
                    <flux:badge color="zinc" size="sm">{{ __('Closed') }}</flux:badge>
                @endif

                <flux:button wire:click="toggleStatus" size="sm">
                    {{ $conversation->status === \App\Enums\ConversationStatus::Open ? __('Close') : __('Reopen') }}
                </flux:button>
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700 md:col-span-2">
            <flux:subheading>{{ __('Assigned to') }}</flux:subheading>
            <form wire:submit="assign" class="mt-2 flex items-center gap-3">
                <flux:select wire:model="assigned_user_id" placeholder="{{ __('Unassigned') }}" class="max-w-xs">
                    <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                    @foreach ($this->assignableUsers as $user)
                        <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:button type="submit" size="sm">{{ __('Save') }}</flux:button>
            </form>
        </div>
    </div>

    <div class="mt-6 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <flux:subheading>{{ __('Messages') }}</flux:subheading>

        <div class="mt-4 flex flex-col gap-3">
            @forelse ($this->messages as $message)
                <div
                    wire:key="message-{{ $message->id }}"
                    class="max-w-lg rounded-lg p-3 text-sm {{ $message->direction === \App\Enums\MessageDirection::Outbound ? 'ml-auto bg-blue-100 dark:bg-blue-900' : 'bg-zinc-100 dark:bg-zinc-800' }}"
                >
                    <div class="text-xs font-medium text-zinc-500">
                        {{ $message->direction === \App\Enums\MessageDirection::Outbound ? __('Restaurant') : __('Customer') }}
                        &middot;
                        {{ ($message->sent_at ?? $message->received_at ?? $message->created_at)->format('M j, Y g:i A') }}
                    </div>
                    <div class="mt-1">{{ $message->content }}</div>
                    @if ($message->direction === \App\Enums\MessageDirection::Outbound && $message->status)
                        <div class="mt-1 text-[10px] uppercase tracking-wide text-zinc-400">{{ $message->status->value }}</div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-zinc-500">{{ __('No messages yet.') }}</p>
            @endforelse
        </div>

        <div class="mt-6 border-t border-neutral-200 pt-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Send a WhatsApp message') }}</flux:subheading>

            @if (session('status'))
                <p class="text-xs text-green-600">{{ session('status') }}</p>
            @endif

            <form wire:submit="sendMessage" class="mt-3 flex flex-col gap-3">
                <flux:textarea wire:model="message_content" label="{{ __('Message') }}" />
                @error('message_content') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

                <div>
                    <flux:button type="submit" variant="primary" size="sm">{{ __('Send') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
</section>
