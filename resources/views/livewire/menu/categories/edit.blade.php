<?php

use App\Models\Category;
use App\Services\Menu\UpdateCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component {
    public Category $category;

    public string $name = '';
    public bool $is_active = true;

    public function mount(Category $category): void
    {
        $this->authorize('update', $category);

        $this->category = $category;
        $this->name = $category->name;
        $this->is_active = $category->is_active;
    }

    /**
     * Update this category. Re-checks authorization here too, since this
     * action runs on its own AJAX request and does not re-run mount().
     */
    public function save(): void
    {
        $this->authorize('update', $this->category);

        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('categories', 'name')
                    ->where('restaurant_id', Auth::user()->restaurant_id)
                    ->ignore($this->category->id),
            ],
            'is_active' => ['required', 'boolean'],
        ]);

        app(UpdateCategory::class)->handle($this->category, $validated);

        session()->flash('status', __('Category updated.'));

        $this->redirect(route('menu.categories.index'), navigate: true);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Edit category') }}</flux:heading>
    <flux:subheading>{{ __('Update this menu category.') }}</flux:subheading>

    <form wire:submit="save" class="mt-6 flex flex-col gap-6">
        <flux:input wire:model="name" label="{{ __('Name') }}" type="text" required autofocus />

        <flux:switch wire:model="is_active" label="{{ __('Active') }}" />

        <div class="flex items-center gap-4">
            <flux:button type="submit" variant="primary">{{ __('Save changes') }}</flux:button>
            <flux:button :href="route('menu.categories.index')" variant="ghost" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
