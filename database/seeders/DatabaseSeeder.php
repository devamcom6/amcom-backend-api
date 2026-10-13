<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data — safe to run in every environment.
        $this->call([
            RolePermissionSeeder::class,
            ContentSeeder::class,
        ]);

        // Demo accounts and sample data never reach production.
        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
