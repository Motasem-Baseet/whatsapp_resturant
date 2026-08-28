<?php

use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Registered outside the 'web' and 'api' middleware groups entirely (see
| bootstrap/app.php's withRouting 'then' callback) - Meta's webhook
| requests are unauthenticated server-to-server calls with no session
| and no CSRF token, so they must not go through EnsureUserIsActive,
| IdentifyTenant, or CSRF verification. Authenticity is instead
| established per-request by the controller itself (verify_token for
| GET, X-Hub-Signature-256 for POST).
|
*/

Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])
    ->name('webhooks.whatsapp.verify');

Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])
    ->name('webhooks.whatsapp.handle');
