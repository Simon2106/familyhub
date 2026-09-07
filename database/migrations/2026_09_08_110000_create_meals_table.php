<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The weekly meal plan, one row per filled cell.
 *
 * `title` is always set, even when a recipe is attached, so the plan survives
 * the recipe being deleted and so a free-text meal — "leftovers", "out",
 * "Nanny's" — is exactly as much of a meal as a linked recipe is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->date('on');

            // breakfast | lunch | dinner
            $table->string('slot', 16)->default('dinner');

            $table->string('title');
            $table->foreignId('recipe_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // One meal per cell, like the squares on a paper planner. Also the
            // index the week grid is read by.
            $table->unique(['household_id', 'on', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meals');
    }
};
