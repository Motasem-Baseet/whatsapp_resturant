<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates an order and its line items atomically, with every money
 * value computed server-side.
 *
 * restaurant_id is taken explicitly from the given restaurant, not from
 * TenantContext - matching the pattern established from Phase 4 onward.
 * Unlike CreateConversation (which trusts the caller's prior
 * validation), this service re-validates customer/conversation/creator
 * ownership itself, and independently re-resolves every product from
 * the database rather than trusting client-supplied names or prices -
 * this phase's spec explicitly calls out not trusting "customer
 * ownership", "conversation ownership", "product prices", "line
 * totals", "subtotal", or "total" from the client, which is a higher
 * bar than earlier services needed. The database's composite foreign
 * keys remain the final backstop regardless.
 */
class CreateOrder
{
    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     *
     * @throws InvalidArgumentException if the customer, conversation, or
     *         creator does not belong to $restaurant, if $items is
     *         empty, or if any product is invalid, inactive, in an
     *         inactive category, or belongs to another restaurant.
     */
    public function handle(
        Restaurant $restaurant,
        Customer $customer,
        array $items,
        ?Conversation $conversation = null,
        ?User $createdBy = null,
        ?string $notes = null,
    ): Order {
        if ($customer->restaurant_id !== $restaurant->id) {
            throw new InvalidArgumentException('The customer must belong to the given restaurant.');
        }

        if ($conversation !== null && $conversation->restaurant_id !== $restaurant->id) {
            throw new InvalidArgumentException('The conversation must belong to the given restaurant.');
        }

        if ($createdBy !== null && $createdBy->restaurant_id !== $restaurant->id) {
            throw new InvalidArgumentException('The creating user must belong to the given restaurant.');
        }

        $normalizedItems = $this->normalizeItems($items);

        if (empty($normalizedItems)) {
            throw new InvalidArgumentException('An order must have at least one item.');
        }

        return DB::transaction(function () use ($restaurant, $customer, $conversation, $createdBy, $notes, $normalizedItems) {
            $products = $this->resolveOrderableProducts($restaurant, array_keys($normalizedItems));

            $order = new Order([
                'status' => OrderStatus::Pending,
                'notes' => $notes,
            ]);
            $order->restaurant_id = $restaurant->id;
            $order->customer_id = $customer->id;
            $order->conversation_id = $conversation?->id;
            $order->created_by = $createdBy?->id;
            // subtotal/total are not fillable (never accepted from
            // input), so they're set directly here too.
            $order->subtotal = '0.00';
            $order->total = '0.00';
            $order->save();

            $subtotalCents = 0;

            foreach ($normalizedItems as $productId => $quantity) {
                $product = $products[$productId];

                // Integer-cents arithmetic avoids float precision drift
                // for money without needing the bcmath extension.
                $unitPriceCents = (int) round(((float) $product->price) * 100);
                $lineTotalCents = $unitPriceCents * $quantity;
                $subtotalCents += $lineTotalCents;

                $item = new OrderItem([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $this->centsToDecimalString($unitPriceCents),
                    'quantity' => $quantity,
                    'line_total' => $this->centsToDecimalString($lineTotalCents),
                ]);
                $item->restaurant_id = $restaurant->id;
                $item->order_id = $order->id;
                $item->save();
            }

            $subtotal = $this->centsToDecimalString($subtotalCents);

            $order->subtotal = $subtotal;
            $order->total = $subtotal; // total = subtotal for this phase; no tax/fees/discounts yet.
            $order->save();

            return $order->fresh('items');
        });
    }

    /**
     * Merges quantities for duplicate product ids (rather than
     * rejecting them or creating duplicate line items), keyed by
     * product id in submission order.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     * @return array<int, int> product_id => total quantity
     */
    protected function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];

            if ($quantity < 1) {
                continue;
            }

            $normalized[$productId] = ($normalized[$productId] ?? 0) + $quantity;
        }

        return $normalized;
    }

    /**
     * @param  list<int>  $productIds
     * @return \Illuminate\Support\Collection<int, \App\Models\Product>
     */
    protected function resolveOrderableProducts(Restaurant $restaurant, array $productIds): \Illuminate\Support\Collection
    {
        $products = $restaurant->products()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($productIds)) {
            throw new InvalidArgumentException('One or more products are invalid, inactive, in an inactive category, or belong to another restaurant.');
        }

        return $products;
    }

    protected function centsToDecimalString(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
