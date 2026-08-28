<?php

namespace Tests\Feature\WhatsApp;

use App\Models\WhatsAppAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_succeeds_and_echoes_the_challenge_when_the_token_matches(): void
    {
        $account = WhatsAppAccount::factory()->create(['verify_token' => 'correct-token']);

        $response = $this->get('/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'correct-token',
            'hub.challenge' => 'challenge-echo-value',
        ]));

        $response->assertOk();
        $response->assertSee('challenge-echo-value', false);
        $this->assertSame('challenge-echo-value', $response->getContent());
    }

    public function test_verification_fails_with_403_when_the_token_does_not_match_any_account(): void
    {
        WhatsAppAccount::factory()->create(['verify_token' => 'correct-token']);

        $response = $this->get('/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wrong-token',
            'hub.challenge' => 'challenge-echo-value',
        ]));

        $response->assertForbidden();
    }

    public function test_verification_fails_with_403_when_mode_is_not_subscribe(): void
    {
        WhatsAppAccount::factory()->create(['verify_token' => 'correct-token']);

        $response = $this->get('/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'unsubscribe',
            'hub.verify_token' => 'correct-token',
            'hub.challenge' => 'challenge-echo-value',
        ]));

        $response->assertForbidden();
    }

    public function test_verification_fails_for_an_inactive_account(): void
    {
        WhatsAppAccount::factory()->inactive()->create(['verify_token' => 'inactive-token']);

        $response = $this->get('/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'inactive-token',
            'hub.challenge' => 'challenge-echo-value',
        ]));

        $response->assertForbidden();
    }

    public function test_verification_does_not_require_authentication(): void
    {
        $account = WhatsAppAccount::factory()->create(['verify_token' => 'no-auth-token']);

        // No actingAs() call at all - this must work as a fully
        // unauthenticated, un-sessioned request.
        $response = $this->get('/webhooks/whatsapp?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'no-auth-token',
            'hub.challenge' => 'ok',
        ]));

        $response->assertOk();
        unset($account);
    }
}
