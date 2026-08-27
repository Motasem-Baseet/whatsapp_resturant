<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        // User::factory(10)->create();

        // firstOrCreate rather than create(), so re-running db:seed does
        // not fail on the unique email constraint. The email is pinned
        // in both arrays deliberately: firstOrCreate's create path is
        // array_merge($attributes, $values), and raw()'s randomly
        // generated email would otherwise win that merge and silently
        // replace the intended address.
        User::firstOrCreate(
            ['email' => 'test@example.com'],
            User::factory()->raw(['name' => 'Test User', 'email' => 'test@example.com']),
        );
    }
}
