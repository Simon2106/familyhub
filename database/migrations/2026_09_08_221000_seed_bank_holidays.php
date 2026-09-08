<?php

use App\Models\BankHoliday;
use App\Services\Schools\BankHolidays;
use Illuminate\Database\Migrations\Migration;

/**
 * So the wall knows about bank holidays before the first monthly sync runs.
 *
 * Computed rather than fetched: a migration that reaches out to the internet
 * is a migration that fails on a machine with no internet.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(BankHolidays::class)->seed();
    }

    public function down(): void
    {
        BankHoliday::query()->delete();
    }
};
