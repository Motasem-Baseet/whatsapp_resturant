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
            $products = $this->resolveOrderableProducts($restaurant, $normalizedItems);

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

                // null stock_quantity means this product is not
                // stock-tracked (Phase 27) - nothing is deducted, and
                // stock_deducted is recorded false so a later
                // cancellation of this order correctly restores
                // nothing for this line, regardless of whether the
                // product becomes stock-tracked afterwards.
                $stockTracked = $product->stock_quantity !== null;

                $item = new OrderItem([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $this->centsToDecimalString($unitPriceCents),
                    'quantity' => $quantity,
                    'line_total' => $this->centsToDecimalString($lineTotalCents),
                    'stock_deducted' => $stockTracked,
                ]);
                $item->restaurant_id = $restaurant->id;
                $item->order_id = $order->id;
                $item->save();

                if ($stockTracked) {
                    // A single atomic UPDATE ... SET stock_quantity =
                    // stock_quantity - ?, not a read-then-write in PHP -
                    // combined with the row lock already taken in
                    // resolveOrderableProducts() above, this is safe
                    // against concurrent orders for the same product.
                    $product->decrement('stock_quantity', $quantity);
                }
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
     * Resolves and validates every product in the order, row-locked for
     * the remainder of this transaction (Phase 27) - the lock is taken
     * here, before any stock is checked or decremented below, so two
     * concurrent orders for the same product can never both read the
     * same pre-decrement stock value and both decide there is enough:
     * the second transaction blocks on this SELECT until the first
     * commits (or rolls back), at which point it re-reads the
     * now-current stock. This is the standard "SELECT ... FOR UPDATE"
     * pessimistic-locking pattern, not a read-then-write race.
     *
     * @param  array<int, int>  $normalizedItems  product_id => quantity
     * @return \Illuminate\Support\Collection<int, \App\Models\Product>
     *
     * @throws InvalidArgumentException if any product does not exist,
     *         does not belong to $restaurant, is not currently
     *         orderable (Product::isOrderable()), or does not have
     *         enough stock for the requested quantity.
     */
    protected function resolveOrderableProducts(Restaurant $restaurant, array $normalizedItems): \Illuminate\Support\Collection
    {
        $productIds = array_keys($normalizedItems);

        $products = $restaurant->products()
            ->whereIn('id', $productIds)
            ->with('category')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($productIds)) {
            throw new InvalidArgumentException('One or more products are invalid, unavailable, or belong to another restaurant.');
        }

        foreach ($normalizedItems as $productId => $quantity) {
            $product = $products[$productId];

            if (! $product->isOrderable()) {
                throw new InvalidArgumentException('One or more products are invalid, unavailable, or belong to another restaurant.');
            }

            // Checked against the already-merged (duplicate-normalized)
            // quantity - never per submitted line - so two lines for
            // the same product can never together oversell stock that
            // would have correctly rejected their combined total.
            if ($product->stock_quantity !== null && $product->stock_quantity < $quantity) {
                throw new InvalidArgumentException('Insufficient stock for one or more products.');
            }
        }

        return $products;
    }

    protected function centsToDecimalString(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
