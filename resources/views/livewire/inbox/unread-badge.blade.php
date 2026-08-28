<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * Renders the "Inbox" sidebar nav item with a live unread-conversation
 * count. Lives on its own (rather than folding into the sidebar's plain
 * Blade layout) purely so it can hold its own Echo listener and
 * re-render independently of whatever page is currently open - the
 * badge should stay live no matter which page a user is looking at,
 * not only while the inbox itself is open.
 *
 * The sidebar embeds this behind the same @can('viewAny', Conversation)
 * check already used for every other nav item (see
 * components/layouts/app/sidebar.blade.php), so kitchen users never
 * mount this component at all. unreadCount() re-checks that
 * authorization itself anyway, as defense in depth against this
 * component ever being embedded somewhere without that guard.
 */
new class extends Component {
    #[Computed]
    public function restaurantId(): int
    {
        return Auth::user()->restaurant_id;
    }

    #[Computed]
    public function unreadCount(): int
    {
        $user = Auth::user();

        if (! $user->can('viewAny', Conversation::class)) {
            return 0;
        }

        return $user->restaurant
            ->conversations()
            ->unreadFor($user)
            ->count();
    }

    #[On('echo-private:restaurants.{restaurantId}.inbox,.message.created')]
    public function onMessageCreated(): void
    {
        //
    }
}; ?>

<flux:navlist.item
    icon="inbox"
    :href="route('inbox.index')"
    :current="request()->routeIs('inbox.*') || request()->routeIs('conversations.*')"
    :badge="$this->unreadCount > 0 ? $this->unreadCount : null"
    badge:color="blue"
    wire:navigate
>
    {{ __('Inbox') }}
</flux:navlist.item>
