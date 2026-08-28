<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Order attention thresholds
    |--------------------------------------------------------------------------
    |
    | How many minutes an order may remain in a given status before it is
    | considered to require operational attention - see
    | App\Models\Order::requiresAttention(). Grouped by workflow
    | responsibility rather than by individual status: confirmed and
    | preparing share the "preparing" threshold, since both are the
    | kitchen's own actionable statuses (an order sitting confirmed too
    | long needs to be started; one sitting preparing too long needs to
    | be finished). Values are never accepted from the client - they are
    | server configuration only.
    |
    */

    'attention_thresholds' => [
        'pending' => (int) env('ORDER_ATTENTION_THRESHOLD_PENDING', 10),
        'preparing' => (int) env('ORDER_ATTENTION_THRESHOLD_PREPARING', 15),
        'ready' => (int) env('ORDER_ATTENTION_THRESHOLD_READY', 10),
    ],

];
