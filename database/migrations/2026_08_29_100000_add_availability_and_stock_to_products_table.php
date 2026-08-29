<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Phase 27 backward compatibility: both new columns are additive
     * and default to values that preserve every existing product's
     * current, de-facto behavior with zero backfill computation needed.
     *
     *  - is_available defaults to true - every existing active product
     *    was, in effect, already "available" (there was no other state);
     *    this just makes that state explicit and independently
     *    togglable from is_active going forward.
     *  - stock_quantity defaults to null, meaning "not stock-tracked" -
     *    an existing product keeps being orderable regardless of
     *    quantity, exactly as before this column existed, until an
     *    owner explicitly opts it into tracking by setting a real
     *    number via the product create/edit form. See
     *    Product::isOrderable() for how null is treated as "no stock
     *    constraint" rather than "zero stock".
     *
     * order_items.stock_deducted defaults to false, so every existing
     * (pre-Phase-27) order item is correctly recorded as having never
     * had stock deducted for it - cancelling an old order must not
     * restore stock that was never actually taken from it. New order
     * items get this set explicitly by CreateOrder based on whether the
     * product was stock-tracked at the moment the order was placed,
     * which is what UpdateOrderStatus's cancellation-time stock
     * restoration reads - never inferred from the product's current
     * state, which could have changed since.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_available')->default(true)->after('is_active');
            $table->unsignedInteger('stock_quantity')->nullable()->after('is_available');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('stock_deducted')->default(false)->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_available', 'stock_quantity']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('stock_deducted');
        });
    }
};
