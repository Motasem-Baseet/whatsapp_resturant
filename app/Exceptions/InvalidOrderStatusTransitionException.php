<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use RuntimeException;

class InvalidOrderStatusTransitionException extends RuntimeException
{
    public static function from(OrderStatus $from, OrderStatus $to): self
    {
        return new self("Cannot transition an order from \"{$from->value}\" to \"{$to->value}\".");
    }
}
