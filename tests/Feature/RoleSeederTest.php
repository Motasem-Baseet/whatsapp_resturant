<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_owner_cashier_and_kitchen_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertTrue(Role::where('name', 'owner')->exists());
        $this->assertTrue(Role::where('name', 'cashier')->exists());
        $this->assertTrue(Role::where('name', 'kitchen')->exists());
    }

    public function test_running_the_role_seeder_repeatedly_does_not_create_duplicate_roles(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(RoleSeeder::class);

        $this->assertSame(3, Role::count());
    }

    public function test_the_full_database_seeder_runs_more_than_once_without_error(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(3, Role::count());
        $this->assertSame(1, User::where('email', 'test@example.com')->count());
    }
}
