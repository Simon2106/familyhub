<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The things a household is counting the days to.
 *
 * A table of its own rather than a flag on events, because the two things it
 * counts are not the same shape. An event's countdown must survive the next
 * iCloud sync overwriting the row it points at, and "Cornwall" is often a
 * thing the family is looking forward to weeks before anybody puts it in a
 * calendar.
 *
 * Birthdays are not in here at all: they live on the member and recur by
 * themselves, so nobody has to remember to add next year's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countdowns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            // Set when the countdown was made from an event, so deleting the
            // event takes the countdown with it.
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('label');
            $table->date('on');

            $table->timestamps();

            $table->index(['household_id', 'on']);
        });

        Schema::table('members', function (Blueprint $table) {
            // Year included: a household that knows somebody is turning eight
            // can say so, and one that only knows the day still works.
            $table->date('birthday')->nullable()->after('is_child');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countdowns');
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn('birthday'));
    }
};
