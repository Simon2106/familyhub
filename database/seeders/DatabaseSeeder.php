<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the household itself. Demo calendar content is deliberately kept out
     * of the default seed — run `php artisan familyhub:seed-demo` for that.
     */
    public function run(): void
    {
        $this->call(HouseholdSeeder::class);
    }
}
