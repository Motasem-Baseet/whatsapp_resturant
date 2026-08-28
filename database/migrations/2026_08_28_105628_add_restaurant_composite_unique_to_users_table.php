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
        // Lets Conversation carry a composite foreign key down to
        // (assigned_user_id, restaurant_id), so the database itself can
        // never assign a conversation to a user from a different
        // restaurant. restaurant_id stays nullable here (unrelated to
        // this index) for the future platform-admin case established
        // in Phase 1 - a composite unique index tolerates NULLs fine,
        // and a NULL in any column of a composite foreign key simply
        // means that constraint is not checked for that row (i.e. an
        // unassigned conversation, assigned_user_id = NULL, is exempt).
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['id', 'restaurant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['id', 'restaurant_id']);
        });
    }
};
