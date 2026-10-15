<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the house has been doing.
 *
 * One row per state change on an entity somebody chose to watch in /admin.
 * Not every entity in Home Assistant: a Zigbee network invents a couple of
 * hundred of those and a feed of all of them is a feed nobody reads.
 *
 * Kept a month and pruned nightly — this is "what happened this afternoon",
 * not an archive of the house.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->string('entity_id');

            /** people | doors | heating | lights | other — set in /admin. */
            $table->string('category')->default('other');

            /** What the household calls it, so the sentence reads like English. */
            $table->string('label');

            $table->string('from_state')->nullable();
            $table->string('to_state');

            $table->timestamp('at');

            $table->timestamps();

            // Every read is "this household, newest first, within a window".
            $table->index(['household_id', 'at']);
            $table->index(['household_id', 'entity_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
