<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, SystemSeeder::class]);

        if (app()->environment('local', 'testing') || $this->command?->confirm('Seed demo data?', false)) {
            $this->call(DemoSeeder::class);
        }
    }
}
