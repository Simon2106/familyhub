<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a proposed week waits to be looked at.
 *
 * Not the meal plan. Nothing here is on anybody's Thursday until somebody
 * taps Keep, which is the entire reason it is a table of its own rather
 * than meals with a flag on them — a flag gets forgotten by the next query
 * that reads meals, and a family finds itself eating something it never
 * agreed to.
 *
 * It is a table rather than a component property because the proposal is
 * made in one place (asking the assistant, possibly out loud at the wall)
 * and looked at in another (the planner, possibly on somebody's phone half
 * an hour later).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plan_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->date('week_start');
            $table->string('slot', 16)->default('dinner');

            /** @var list<array{on: string, title: string, recipe_id: ?int, why: ?string}> */
            $table->json('entries');

            /** What was asked for, in the family's own words. */
            $table->string('asked_for', 500)->nullable();

            /** The assistant's one line about what it did and why. */
            $table->string('note', 500)->nullable();

            $table->timestamps();

            // One live proposal per week per slot: a second "plan next week"
            // replaces the first rather than stacking two sets of ghosts on
            // the same Thursday.
            $table->unique(['household_id', 'week_start', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_proposals');
    }
};
