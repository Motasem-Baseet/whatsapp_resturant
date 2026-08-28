<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when the WhatsApp Cloud API rejects or fails to accept an
 * outbound message. Callers must not create a "sent" Message row when
 * this is thrown - the send genuinely failed. The message is intended
 * to be safe to show to an owner/cashier; diagnostic detail (never
 * including the access token) belongs in the log context passed at the
 * throw site, not in the exception message itself.
 */
class WhatsAppMessageSendException extends Exception
{
    //
}
