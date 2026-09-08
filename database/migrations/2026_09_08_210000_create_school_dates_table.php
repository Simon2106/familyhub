<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When each school is open, and when it is not.
 *
 * Terms are entered; holidays are the gaps between them. That is the way round
 * a school PDF is actually written — "Autumn term: 2 September to 22 October" —
 * and it means half term cannot be forgotten, because it is simply the space
 * between two terms rather than a row somebody had to remember to add.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            // SG, HT. Short enough to sit on a day in a week strip.
            $table->string('short_code', 8)->nullable()->after('name');
            $table->string('term_ical_url', 2048)->nullable()->after('short_code');
        });

        Schema::create('school_dates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained()->cascadeOnDelete();

            // term   — the school is open; holidays are derived from the gaps
            // inset  — a single day the children are off and the staff are in
            // closed — a named closure a feed told us about directly
            $table->string('kind', 16)->default('term');

            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');

            // manual | ical — so re-syncing a feed cannot wipe typed-in dates.
            $table->string('source', 16)->default('manual');

            $table->timestamps();

            $table->index(['place_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_dates');

        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn(['short_code', 'term_ical_url']);
        });
    }
};
