<?php

namespace App\Services\WhatsApp;

/**
 * Isolated in its own service (rather than inline in the controller) so
 * the signature logic is independently testable and cannot accidentally
 * drift from being applied consistently.
 *
 * Meta's webhook payload does not identify which app_secret to use
 * before it has been parsed enough to resolve the account via
 * phone_number_id - so the controller parses the raw body only to
 * extract that one field (read-only), then hands the *same untouched
 * raw bytes* here for the actual HMAC check. The signature is never
 * computed against a re-encoded/mutated version of the payload.
 */
class VerifyWebhookSignature
{
    /**
     * @param  string  $rawBody  The exact, unmodified request body bytes.
     * @param  string|null  $signatureHeader  The raw X-Hub-Signature-256 header value, e.g. "sha256=abcdef...".
     */
    public function verify(string $rawBody, ?string $signatureHeader, string $appSecret): bool
    {
        if (! is_string($signatureHeader) || ! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $provided = substr($signatureHeader, strlen('sha256='));
        $expected = hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $provided);
    }
}
