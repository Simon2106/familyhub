<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Database\Seeders\HouseholdSeeder;
use Illuminate\Console\Command;

class SeedDemoCommand extends Command
{
    protected $signature = 'familyhub:seed-demo
                            {--fresh : Wipe the database first}
                            {--household-only : Seed the household and members but no demo events}';

    protected $description = 'Seed the household from .env, plus a fortnight of demo calendar content';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            if (app()->isProduction() && ! $this->confirm('APP_ENV is production. Really wipe the database?', false)) {
                $this->components->warn('Aborted.');

                return self::FAILURE;
            }

            $this->call('migrate:fresh', ['--force' => true]);
        }

        $this->call('db:seed', ['--class' => HouseholdSeeder::class, '--force' => true]);

        if (! $this->option('household-only')) {
            $this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true]);
        }

        return self::SUCCESS;
    }
}
