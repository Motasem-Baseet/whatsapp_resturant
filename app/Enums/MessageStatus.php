<?php

namespace App\Enums;

/**
 * Provider-neutral delivery status for an outbound message. Deliberately
 * a separate enum/column from MessageDirection - direction (inbound/
 * outbound) and delivery state are different concerns and must not be
 * conflated.
 */
enum MessageStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
}
