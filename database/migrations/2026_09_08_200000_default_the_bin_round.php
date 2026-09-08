<?php

use App\Models\Household;
use Illuminate\Database\Migrations\Migration;

/**
 * The round at 5 The Ridgeway, Marlow.
 *
 * Buckinghamshire publishes a PDF and nothing machine-readable, so the pattern
 * is written down: Tuesdays, food every week, and the rest alternating with
 * Tuesday 15 September 2026 as a week A.
 *
 * Data rather than schema, following the first-name-alias migration. Guarded,
 * so a household that has already said where its bins come from is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $household = Household::query()->orderBy('id')->first();

        if (! $household || $household->binSource() !== 'none') {
            return;
        }

        $household->setBinPattern([
            'weekday' => 2,
            'anchor' => '2026-09-15',
            'weekly' => ['food'],
            'week_a' => ['recycling', 'paper', 'garden', 'electricals'],
            'week_b' => ['refuse'],
        ]);

        $household->setBinSource('pattern');
    }

    public function down(): void
    {
        Household::query()->orderBy('id')->first()?->setBinSource('none');
    }
};
