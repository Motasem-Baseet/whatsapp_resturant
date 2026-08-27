<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * The MVP's fixed set of roles.
     */
    public const ROLES = ['owner', 'cashier', 'kitchen'];

    /**
     * Run the database seeds.
     *
     * Uses findOrCreate() so this is safe to run repeatedly (fresh
     * migrations, re-seeding, CI) without creating duplicate roles.
     */
    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role);
        }
    }
}
