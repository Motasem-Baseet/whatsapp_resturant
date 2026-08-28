<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Restaurant;
use App\Models\WhatsAppAccount;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_restaurant_can_have_many_whatsapp_accounts(): void
    {
        $restaurant = Restaurant::factory()->create();
        $accountA = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);
        $accountB = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->assertTrue($restaurant->whatsAppAccounts->contains($accountA));
        $this->assertTrue($restaurant->whatsAppAccounts->contains($accountB));
    }

    public function test_a_whatsapp_account_belongs_to_a_restaurant(): void
    {
        $restaurant = Restaurant::factory()->create();
        $account = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurant->id]);

        $this->assertTrue($account->restaurant->is($restaurant));
    }

    public function test_phone_number_id_must_be_globally_unique(): void
    {
        WhatsAppAccount::factory()->create(['phone_number_id' => '1234567890']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        WhatsAppAccount::factory()->create(['phone_number_id' => '1234567890']);
    }

    public function test_access_token_is_encrypted_at_rest(): void
    {
        $account = WhatsAppAccount::factory()->create();
        $account->access_token = 'plaintext-secret-token';
        $account->save();

        $raw = \Illuminate\Support\Facades\DB::table('whatsapp_accounts')->where('id', $account->id)->value('access_token');

        $this->assertStringNotContainsString('plaintext-secret-token', $raw);
        $this->assertSame('plaintext-secret-token', $account->fresh()->access_token);
    }

    public function test_secrets_are_hidden_from_array_and_json_serialization(): void
    {
        $account = WhatsAppAccount::factory()->create([
            'access_token' => 'super-secret-token',
            'app_secret' => 'super-secret-app-secret',
            'verify_token' => 'super-secret-verify-token',
        ]);

        $array = $account->toArray();

        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('app_secret', $array);
        $this->assertArrayNotHasKey('verify_token', $array);

        $json = $account->toJson();

        $this->assertStringNotContainsString('super-secret-token', $json);
        $this->assertStringNotContainsString('super-secret-app-secret', $json);
        $this->assertStringNotContainsString('super-secret-verify-token', $json);
    }

    public function test_access_token_is_not_mass_assignable(): void
    {
        $restaurant = Restaurant::factory()->create();

        $account = new WhatsAppAccount([
            'phone_number_id' => 'abc123',
            'access_token' => 'should-not-be-set',
        ]);

        $this->assertNull($account->access_token);
    }

    public function test_is_active_casts_to_boolean(): void
    {
        $account = WhatsAppAccount::factory()->create(['is_active' => true]);

        $this->assertIsBool($account->fresh()->is_active);
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_whatsapp_accounts_are_scoped_to_the_current_tenant(): void
    {
        $restaurantA = Restaurant::factory()->create();
        $restaurantB = Restaurant::factory()->create();
        $accountA = WhatsAppAccount::factory()->create(['restaurant_id' => $restaurantA->id]);
        WhatsAppAccount::factory()->create(['restaurant_id' => $restaurantB->id]);

        app(TenantContext::class)->set($restaurantA);

        $this->assertSame([$accountA->id], WhatsAppAccount::query()->pluck('id')->all());
    }

    public function test_whatsapp_accounts_are_not_scoped_when_no_tenant_is_set(): void
    {
        // Webhook resolution runs before any tenant context exists -
        // the account must be findable globally by phone_number_id in
        // that state (see WhatsAppAccount's class docblock).
        WhatsAppAccount::factory()->create(['phone_number_id' => 'global-lookup-123']);

        $this->assertNotNull(
            WhatsAppAccount::query()->where('phone_number_id', 'global-lookup-123')->first()
        );
    }
}
