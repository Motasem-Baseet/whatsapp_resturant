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

    /**
     * Display-only metadata for comparison() metrics - labels and
     * whether a value is currency (2 decimals) or a plain count.
     * Presentation formatting only; the metrics themselves are computed
     * entirely by GetOrderReport.
     *
     * @return array<string, array{label: string, currency: bool}>
     */
    protected function comparisonMetricMeta(): array
    {
        return [
            'revenue' => ['label' => __('Revenue'), 'currency' => true],
            'total_orders' => ['label' => __('Total orders'), 'currency' => false],
            'completed_orders' => ['label' => __('Completed orders'), 'currency' => false],
            'average_order_value' => ['label' => __('Average order value'), 'currency' => false],
            'new_customers' => ['label' => __('New customers'), 'currency' => false],
        ];
    }

    public function comparisonMetricLabel(string $key): string
    {
        return $this->comparisonMetricMeta()[$key]['label'] ?? $key;
    }

    protected function isCurrencyMetric(string $key): bool
    {
        return $this->comparisonMetricMeta()[$key]['currency'] ?? false;
    }

    public function formatComparisonValue(string $key, float $value): string
    {
        return $this->isCurrencyMetric($key) ? number_format($value, 2) : number_format($value, 0);
    }

    public function formatComparisonChange(string $key, float $change): string
    {
        $formatted = $this->isCurrencyMetric($key) ? number_format(abs($change), 2) : number_format(abs($change), 0);
        $sign = $change > 0 ? '+' : ($change < 0 ? '-' : '');

        return $sign.$formatted;
    }

    /**
     * "New" for a null percentage (previous period was zero and current
     * is above zero - a mathematically undefined/infinite percentage,
     * never fabricated as a number), otherwise a signed percentage.
     */
    public function formatPercentageChange(?float $percentageChange): string
    {
        if ($percentageChange === null) {
            return __('New');
        }

        $sign = $percentageChange > 0 ? '+' : '';

        return "{$sign}{$percentageChange}%";
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

    <div class="mt-6 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <div class="flex items-center justify-between">
            <flux:subheading>{{ __('Compared to previous period') }}</flux:subheading>
            <span class="text-sm text-zinc-500">
                {{ $this->report['comparison']['previous_period']['start']->format('M j, Y') }}
                &ndash;
                {{ $this->report['comparison']['previous_period']['end']->format('M j, Y') }}
            </span>
        </div>

        <flux:table class="mt-3">
            <flux:table.columns>
                <flux:table.column>{{ __('Metric') }}</flux:table.column>
                <flux:table.column>{{ __('Current') }}</flux:table.column>
                <flux:table.column>{{ __('Previous') }}</flux:table.column>
                <flux:table.column>{{ __('Change') }}</flux:table.column>
                <flux:table.column>{{ __('% change') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->report['comparison']['metrics'] as $key => $metric)
                    <flux:table.row wire:key="comparison-{{ $key }}">
                        <flux:table.cell>{{ $this->comparisonMetricLabel($key) }}</flux:table.cell>
                        <flux:table.cell>{{ $this->formatComparisonValue($key, (float) $metric['current']) }}</flux:table.cell>
                        <flux:table.cell>{{ $this->formatComparisonValue($key, (float) $metric['previous']) }}</flux:table.cell>
                        <flux:table.cell>{{ $this->formatComparisonChange($key, (float) $metric['change']) }}</flux:table.cell>
                        <flux:table.cell>{{ $this->formatPercentageChange($metric['percentage_change']) }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
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

            @if ($this->report['best_period'] !== null)
                @php $best = $this->report['best_period']; @endphp
                <div class="mt-3 rounded-lg bg-zinc-50 p-3 text-sm dark:bg-zinc-800">
                    {{ $best['unit'] === 'day' ? __('Best day') : __('Best week') }}:
                    <span class="font-semibold">{{ $best['label'] }}</span>
                    &mdash; {{ number_format((float) $best['revenue'], 2) }} {{ __('revenue') }}
                    ({{ __(':count orders', ['count' => $best['order_count']]) }})
                </div>
            @else
                <p class="mt-3 text-sm text-zinc-500">{{ __('No revenue in this period yet.') }}</p>
            @endif
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
                    <flux:table.column>{{ __('% of revenue') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->report['top_products'] as $product)
                        <flux:table.row wire:key="top-product-{{ $loop->index }}">
                            <flux:table.cell>{{ $product->product_name }}</flux:table.cell>
                            <flux:table.cell>{{ $product->total_quantity }}</flux:table.cell>
                            <flux:table.cell>{{ $product->order_count }}</flux:table.cell>
                            <flux:table.cell>{{ number_format((float) $product->total_revenue, 2) }}</flux:table.cell>
                            <flux:table.cell>{{ $product->revenue_percentage }}%</flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('No products ordered in this period.') }}</flux:table.cell>
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

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Customer behavior') }}</flux:subheading>

            @php $retention = $this->report['customer_retention']; @endphp
            <div class="mt-3 grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-zinc-500">{{ __('New customers') }}</p>
                    <p class="text-xl font-semibold">{{ $retention['new_customers'] }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-500">{{ __('Returning customers') }}</p>
                    <p class="text-xl font-semibold">{{ $retention['returning_customers'] }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-500">{{ __('Repeat customers') }}</p>
                    <p class="text-xl font-semibold">{{ $retention['repeat_customers'] }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-500">{{ __('Customers with orders') }}</p>
                    <p class="text-xl font-semibold">{{ $retention['customers_with_orders'] }}</p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:subheading>{{ __('Operational performance') }}</flux:subheading>

            @php $ops = $this->report['operational_performance']; @endphp
            <div class="mt-3 grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-zinc-500">{{ __('Pending → Confirmed') }}</p>
                    <p class="text-xl font-semibold">{{ $ops['avg_pending_to_confirmed_minutes'] !== null ? __(':minutes min', ['minutes' => $ops['avg_pending_to_confirmed_minutes']]) : __('No data') }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-500">{{ __('Confirmed → Preparing') }}</p>
                    <p class="text-xl font-semibold">{{ $ops['avg_confirmed_to_preparing_minutes'] !== null ? __(':minutes min', ['minutes' => $ops['avg_confirmed_to_preparing_minutes']]) : __('No data') }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-500">{{ __('Preparing → Ready') }}</p>
                    <p class="text-xl font-semibold">{{ $ops['avg_preparing_to_ready_minutes'] !== null ? __(':minutes min', ['minutes' => $ops['avg_preparing_to_ready_minutes']]) : __('No data') }}</p>
                </div>
                <div>
                    <p class="text-sm text-zinc-500">{{ __('Avg. fulfillment time') }}</p>
                    <p class="text-xl font-semibold">{{ $ops['avg_fulfillment_minutes'] !== null ? __(':minutes min', ['minutes' => $ops['avg_fulfillment_minutes']]) : __('No data') }}</p>
                </div>
            </div>
            <p class="mt-3 text-sm text-zinc-500">{{ __('Based on :count completed orders.', ['count' => $ops['completed_sample_size']]) }}</p>
        </div>
    </div>
</section>
