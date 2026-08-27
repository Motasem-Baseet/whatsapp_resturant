<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Uses findOrCreate() so this is safe to run repeatedly (fresh
     * migrations, re-seeding, CI) without creating duplicate roles.
     */
    public function run(): void
    {
        Role::findOrCreate('owner');
    }
}
