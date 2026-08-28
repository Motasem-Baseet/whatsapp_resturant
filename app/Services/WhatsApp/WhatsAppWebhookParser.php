<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Carbon;

/**
 * Isolates all knowledge of the WhatsApp Cloud API's nested webhook JSON
 * structure (entry[].changes[].value.{metadata,contacts,messages,
 * statuses}) in one place. The rest of the application only ever sees
 * flat, normalized arrays - it does not need to know where inside the
 * provider payload any of these values came from, and a future
 * different provider's parser could produce the same shapes.
 */
class WhatsAppWebhookParser
{
    /**
     * The phone_number_id identifying which restaurant's WhatsApp
     * business number this payload was delivered to. This is read
     * before anything else - it is what resolves the WhatsAppAccount
     * (and therefore the restaurant) that everything else in the
     * payload must be processed within.
     */
    public function extractPhoneNumberId(array $payload): ?string
    {
        $value = data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Normalized incoming text messages across every entry/change in
     * the payload. Non-text message types and messages missing a
     * sender phone or provider message id are silently skipped here -
     * skipping is itself the "unsupported message does not crash the
     * webhook" behaviour for this phase.
     *
     * @return list<array{provider_message_id: string, sender_phone: string, sender_name: ?string, message_type: string, message_body: ?string, timestamp: ?Carbon}>
     */
    public function extractMessages(array $payload): array
    {
        $messages = [];

        foreach (data_get($payload, 'entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $value = data_get($change, 'value', []);
                $contactsByWaId = collect(data_get($value, 'contacts', []))->keyBy('wa_id');

                foreach (data_get($value, 'messages', []) as $raw) {
                    $providerMessageId = data_get($raw, 'id');
                    $senderPhone = data_get($raw, 'from');

                    if (! is_string($providerMessageId) || $providerMessageId === ''
                        || ! is_string($senderPhone) || $senderPhone === '') {
                        continue;
                    }

                    $messages[] = [
                        'provider_message_id' => $providerMessageId,
                        'sender_phone' => $senderPhone,
                        'sender_name' => data_get($contactsByWaId->get($senderPhone), 'profile.name'),
                        'message_type' => (string) data_get($raw, 'type', 'unknown'),
                        'message_body' => data_get($raw, 'text.body'),
                        'timestamp' => $this->parseTimestamp(data_get($raw, 'timestamp')),
                    ];
                }
            }
        }

        return $messages;
    }

    /**
     * Normalized delivery status events (sent/delivered/read/failed)
     * across every entry/change in the payload.
     *
     * @return list<array{provider_message_id: string, status: string, timestamp: ?Carbon}>
     */
    public function extractStatuses(array $payload): array
    {
        $statuses = [];

        foreach (data_get($payload, 'entry', []) as $entry) {
            foreach (data_get($entry, 'changes', []) as $change) {
                $value = data_get($change, 'value', []);

                foreach (data_get($value, 'statuses', []) as $raw) {
                    $providerMessageId = data_get($raw, 'id');
                    $status = data_get($raw, 'status');

                    if (! is_string($providerMessageId) || $providerMessageId === '' || ! is_string($status)) {
                        continue;
                    }

                    $statuses[] = [
                        'provider_message_id' => $providerMessageId,
                        'status' => $status,
                        'timestamp' => $this->parseTimestamp(data_get($raw, 'timestamp')),
                    ];
                }
            }
        }

        return $statuses;
    }

    protected function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_numeric($value)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $value);
    }
}
