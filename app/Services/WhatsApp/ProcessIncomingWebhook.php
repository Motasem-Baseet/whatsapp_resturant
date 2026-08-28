<?php

namespace App\Services\WhatsApp;

use App\Facades\Tenant;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates a single verified webhook POST payload: resolves the
 * WhatsAppAccount (and therefore restaurant) from phone_number_id,
 * establishes tenant context explicitly for the duration of processing
 * (IdentifyTenant does not run for this route - see routes/webhooks.php),
 * then delegates each normalized message/status to the appropriate
 * service. Tenant context is always cleared afterward, even on
 * exception, so it can never leak into whatever handles the next
 * request on this worker/process.
 */
class ProcessIncomingWebhook
{
    public function __construct(
        protected WhatsAppWebhookParser $parser,
        protected ProcessIncomingMessage $processIncomingMessage,
        protected UpdateMessageDeliveryStatus $updateMessageDeliveryStatus,
    ) {}

    /**
     * @return bool false only when the payload's phone_number_id does
     *              not match any known, active WhatsAppAccount - the
     *              controller still responds 200 in that case (Meta
     *              expects 200 for anything it should not retry), it is
     *              simply logged as unrecognized.
     */
    public function handle(array $payload): bool
    {
        $phoneNumberId = $this->parser->extractPhoneNumberId($payload);

        if ($phoneNumberId === null) {
            return true;
        }

        $account = WhatsAppAccount::query()
            ->where('phone_number_id', $phoneNumberId)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            Log::warning('WhatsApp webhook received for unknown or inactive phone_number_id.', [
                'phone_number_id' => $phoneNumberId,
            ]);

            return false;
        }

        Tenant::set($account->restaurant);

        try {
            foreach ($this->parser->extractMessages($payload) as $message) {
                $this->processOne($account, $message);
            }

            foreach ($this->parser->extractStatuses($payload) as $status) {
                $this->processStatus($account, $status);
            }
        } finally {
            Tenant::clear();
        }

        return true;
    }

    /**
     * Each message is isolated in its own try/catch so one malformed or
     * unexpected message in a batched payload can never abort
     * processing of the other, independent messages in the same
     * delivery.
     */
    protected function processOne(WhatsAppAccount $account, array $message): void
    {
        if ($message['message_type'] !== 'text') {
            // Unsupported types (image/audio/document/video/sticker/
            // location/etc.) are safely ignored rather than crashing
            // the webhook - no placeholder row is created since there
            // is no meaningful, provider-neutral content to store for
            // them in this phase.
            Log::info('Ignoring unsupported WhatsApp message type.', [
                'restaurant_id' => $account->restaurant_id,
                'message_type' => $message['message_type'],
            ]);

            return;
        }

        try {
            $this->processIncomingMessage->handle($account->restaurant, $message);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function processStatus(WhatsAppAccount $account, array $status): void
    {
        try {
            $this->updateMessageDeliveryStatus->handle($account->restaurant, $status);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
