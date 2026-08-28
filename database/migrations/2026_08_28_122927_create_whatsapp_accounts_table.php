<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->restrictOnDelete();

            // Assigned by Meta and globally unique across the whole
            // WhatsApp Cloud API - not scoped per restaurant like
            // provider_message_id, because there is exactly one system
            // of record for which business phone this id refers to.
            // This is the sole identifier a webhook payload's
            // metadata.phone_number_id can be looked up by to resolve
            // the owning restaurant.
            $table->string('phone_number_id')->unique();

            $table->string('business_account_id')->nullable();
            $table->string('display_phone_number')->nullable();

            // Encrypted at the application layer (see the model's
            // 'encrypted' cast) - stored as text since ciphertext is
            // meaningfully longer than the plaintext token.
            $table->text('access_token');

            // Used only to look up the account during Meta's GET
            // webhook verification request, which carries no other
            // account-identifying data. Not encrypted (the task's own
            // reference cast snippet only encrypts access_token), but
            // never fillable from public input and hidden from model
            // serialization.
            $table->string('verify_token');

            // Optional - enables X-Hub-Signature-256 verification on
            // incoming POST webhooks when set.
            $table->string('app_secret')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['restaurant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
