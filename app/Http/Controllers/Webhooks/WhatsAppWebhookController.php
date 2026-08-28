<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use App\Services\WhatsApp\ProcessIncomingWebhook;
use App\Services\WhatsApp\VerifyWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Deliberately thin - all business logic lives in the WhatsApp service
 * layer (App\Services\WhatsApp\*) so it can be tested without HTTP and
 * reused if another entry point is ever needed. This controller's only
 * job is: read the request, verify authenticity, hand off, respond.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Meta's webhook verification handshake (GET). Meta sends
     * hub.mode / hub.verify_token / hub.challenge as query parameters;
     * PHP automatically rewrites dots in query parameter names to
     * underscores when populating $_GET, so Laravel's Request::query()
     * must be read with underscored names (hub_mode, etc.) - the
     * dotted names simply do not exist as keys.
     *
     * Meta's verification request does not include phone_number_id or
     * any other account identifier - only the verify_token it was
     * configured with. Because of this, the account is looked up by
     * verify_token alone (not phone_number_id). This is safe as long as
     * each WhatsAppAccount is provisioned with a distinct, random
     * verify_token (the whatsapp:configure command and factory both
     * generate one) - there is no hardcoded, application-wide token.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || ! is_string($token) || $token === '') {
            return response('Forbidden', 403);
        }

        $account = WhatsAppAccount::query()
            ->where('verify_token', $token)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return response('Forbidden', 403);
        }

        return response((string) $challenge, 200);
    }

    /**
     * Incoming message/status delivery (POST). Signature verification
     * runs against the raw, untouched request body before any
     * processing - only when the resolved account has an app_secret
     * configured (it is optional, since not every integration enables
     * Meta's App Secret signing).
     *
     * Always responds 200 for anything that is not itself an
     * authenticity failure, per Meta's expectation that a webhook
     * should acknowledge receipt (a duplicate delivery, an unknown
     * message type, or an unrecognized phone_number_id are all
     * "handled", not errors).
     */
    public function handle(Request $request, VerifyWebhookSignature $verifySignature, ProcessIncomingWebhook $process): Response
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true) ?? [];

        $phoneNumberId = data_get($payload, 'entry.0.changes.0.value.metadata.phone_number_id');

        $account = is_string($phoneNumberId)
            ? WhatsAppAccount::query()->where('phone_number_id', $phoneNumberId)->where('is_active', true)->first()
            : null;

        if ($account && filled($account->app_secret)) {
            $signature = $request->header('X-Hub-Signature-256');

            if (! $verifySignature->verify($rawBody, $signature, $account->app_secret)) {
                return response('Forbidden', 403);
            }
        }

        $process->handle($payload);

        return response('OK', 200);
    }
}
