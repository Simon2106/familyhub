<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which section of the Home tab a tile belongs in.
 *
 * Null means "whatever its Home Assistant domain implies", so the default
 * stays in one place and a tile only carries a value once somebody has
 * disagreed with it — which happens often enough to be worth the column: half
 * the lamps in this house are Sonoff plugs, and HA calls those switches.
 *
 * Named `section_override` rather than `section` so the model can have a
 * `section()` that resolves it. Eloquent reads a method matching an attribute
 * name as a relationship and calls it to find out — which recurses until the
 * process runs out of memory, nowhere near the cause.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('home_tiles', function (Blueprint $table) {
            $table->string('section_override', 16)->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('home_tiles', function (Blueprint $table) {
            $table->dropColumn('section_override');
        });
    }
};
