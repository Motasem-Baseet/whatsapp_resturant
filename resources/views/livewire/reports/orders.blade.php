<?php

use App\Models\Order;
use App\Services\Reports\GetOrderReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Historical order reporting/analytics, entirely separate from
 * dashboard.blade.php (Phase 13) - the dashboard stays today-only and
 * operational; this page is date-range/historical. Both apply the
 * identical "cancelled excluded from revenue" rule via their own
 * services (GetDashboardMetrics / GetOrderReport respectively), so the
 * two never disagree despite not sharing code.
 */
new #[Layout('components.layouts.app')] class extends Component {
    public string $range = 'today';

    public string $customStart = '';

    public string $customEnd = '';

    public function mount(): void
    {
        $this->authorize('viewReports', Order::class);
    }

    /**
     * Forging an unrecognised value can only ever fall back to "today"
     * - never reach the match below unhandled - matching this
     * codebase's established filter-allowlist convention (see
     * employees/index.blade.php's validRole()/validStatus()).
     */
    protected function validRange(): string
    {
        return in_array($this->range, ['today', '7days', '30days', 'month', 'custom'], true)
            ? $this->range
            : 'today';
    }

    /**
     * Strict Y-m-d parsing only (matching a native <input type="date">'s
     * own output format) rather than Carbon::parse()'s much more
     * permissive/ambiguous parsing - an empty or unparsable value
     * safely becomes null rather than throwing or guessing.
     */
    protected function parseDateOrNull(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolves whichever preset (or custom input) is selected into a
     * concrete, inclusive [start, end] Carbon pair - this is the only
     * place range selection turns into actual dates, so every other
     * computed property/query builds on the same resolved bounds.
     *
     * A custom end date before the start date is clamped to the start
     * date's own end-of-day (collapsing to a valid single-day range)
     * rather than producing a backwards, empty, or rejected query -
     * the safest of the phase's suggested "safely handle" options,
     * since it can never turn into a nonsensical WHERE clause.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    #[Computed]
    public function dateRange(): array
    {
        $today = today();

        return match ($this->validRange()) {
            'today' => [$today->copy()->startOfDay(), $today->copy()->endOfDay()],
            '7days' => [$today->copy()->subDays(6)->startOfDay(), $today->copy()->endOfDay()],
            '30days' => [$today->copy()->subDays(29)->startOfDay(), $today->copy()->endOfDay()],
            'month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'custom' => $this->resolveCustomRange($today),
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveCustomRange(Carbon $today): array
    {
        $start = $this->parseDateOrNull($this->customStart) ?? $today->copy()->startOfDay();
        $end = $this->parseDateOrNull($this->customEnd) ?? $today->copy()->endOfDay();
        $end = $end->copy()->endOfDay();

        if ($end->lt($start)) {
            $end = $start->copy()->endOfDay();
        }

        return [$start, $end];
    }

    /**
     * Restaurant is taken from the authenticated user, never from any
     * client-suppliable input - the date range is the only thing the
     * client influences, and even that only selects which already-
     * authorized restaurant's own data to aggregate over.
     */
    #[Computed]
    public function report(): array
    {
        [$start, $end] = $this->dateRange;

        return app(GetOrderReport::class)->handle(Auth::user()->restaurant, $start, $end);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl">{{ __('Order Reports') }}</flux:heading>
    <flux:subheading>{{ __('Historical order and revenue analytics for your restaurant.') }}</flux:subheading>

    <div class="mt-6 flex flex-wrap items-end gap-3">
        <flux:select wire:model.live="range" label="{{ __('Date range') }}" class="w-48">
            <flux:select.option value="today">{{ __('Today') }}</flux:select.option>
            <flux:select.option value="7days">{{ __('Last 7 days') }}</flux:select.option>
            <flux:select.option value="30days">{{ __('Last 30 days') }}</flux:select.option>
            <flux:select.option value="month">{{ __('This month') }}</flux:select.option>
            <flux:select.option value="custom">{{ __('Custom range') }}</flux:select.option>
        </flux:select>

        @if ($range === 'custom')
            <flux:input type="date" wire:model.live="customStart" label="{{ __('Start date') }}" />
            <flux:input type="date" wire:model.live="customEnd" label="{{ __('End date') }}" />
        @endif
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Total orders') }}</flux:subheading>
            <p class="mt-1 text-2xl font-semibold">{{ $this->report['summary']['total_orders'] }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Completed') }}</flux:subheading>
            <p class="mt-1 text-2xl font-semibold">{{ $this->report['summary']['completed_orders'] }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Cancelled') }}</flux:subheading>
            <p class="mt-1 text-2xl font-semibold">{{ $this->report['summary']['cancelled_orders'] }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Revenue') }}</flux:subheading>
            <p class="mt-1 text-2xl font-semibold">{{ number_format((float) $this->report['summary']['revenue'], 2) }}</p>
        </div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Average order value') }}</flux:subheading>
            <p class="mt-1 text-2xl font-semibold">{{ number_format((float) $this->report['summary']['average_order_value'], 2) }}</p>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Revenue over time') }}</flux:subheading>

            <flux:table class="mt-3">
                <flux:table.columns>
                    <flux:table.column>{{ __('Period') }}</flux:table.column>
                    <flux:table.column>{{ __('Revenue') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->report['revenue_over_time'] as $point)
                        <flux:table.row>
                            <flux:table.cell>{{ $point['label'] }}</flux:table.cell>
                            <flux:table.cell>{{ number_format((float) $point['revenue'], 2) }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="2" class="text-center text-zinc-500">{{ __('No data for this period.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Orders by status') }}</flux:subheading>

            <flux:table class="mt-3">
                <flux:table.columns>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column>{{ __('Count') }}</flux:table.column>
                    <flux:table.column>{{ __('% of total') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->report['status_breakdown'] as $row)
                        <flux:table.row>
                            <flux:table.cell><flux:badge size="sm">{{ $row['label'] }}</flux:badge></flux:table.cell>
                            <flux:table.cell>{{ $row['count'] }}</flux:table.cell>
                            <flux:table.cell>{{ $row['percentage'] }}%</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Top products') }}</flux:subheading>

            <flux:table class="mt-3">
                <flux:table.columns>
                    <flux:table.column>{{ __('Product') }}</flux:table.column>
                    <flux:table.column>{{ __('Quantity') }}</flux:table.column>
                    <flux:table.column>{{ __('Orders') }}</flux:table.column>
                    <flux:table.column>{{ __('Revenue') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->report['top_products'] as $product)
                        <flux:table.row wire:key="top-product-{{ $loop->index }}">
                            <flux:table.cell>{{ $product->product_name }}</flux:table.cell>
                            <flux:table.cell>{{ $product->total_quantity }}</flux:table.cell>
                            <flux:table.cell>{{ $product->order_count }}</flux:table.cell>
                            <flux:table.cell>{{ number_format((float) $product->total_revenue, 2) }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="text-center text-zinc-500">{{ __('No products ordered in this period.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <div class="flex items-center justify-between">
                <flux:subheading>{{ __('Top customers') }}</flux:subheading>
                <span class="text-sm text-zinc-500">{{ __(':count new', ['count' => $this->report['new_customers']]) }}</span>
            </div>

            <flux:table class="mt-3">
                <flux:table.columns>
                    <flux:table.column>{{ __('Customer') }}</flux:table.column>
                    <flux:table.column>{{ __('Orders') }}</flux:table.column>
                    <flux:table.column>{{ __('Total spent') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->report['top_customers'] as $customer)
                        <flux:table.row wire:key="top-customer-{{ $customer->id }}">
                            <flux:table.cell>{{ $customer->name }}</flux:table.cell>
                            <flux:table.cell>{{ $customer->orders_count }}</flux:table.cell>
                            <flux:table.cell>{{ number_format((float) ($customer->orders_sum_total ?? 0), 2) }}</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="3" class="text-center text-zinc-500">{{ __('No customer activity in this period.') }}</flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </div>
</section>
