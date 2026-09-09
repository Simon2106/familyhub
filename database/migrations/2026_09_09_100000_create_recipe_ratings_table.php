<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the household thought of an idea.
 *
 * One row per person per idea, not per time it was cooked: "one per adult,
 * shown as an average" is what a family actually holds in its head, and a
 * history of every individual dinner's score is a spreadsheet nobody reads.
 * Cooking it again updates your row.
 *
 * Adults give stars, children give a thumb, and the two are kept apart because
 * they answer different questions — a meal the children love and the adults
 * are tired of is a real and useful thing to be able to see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // 1–5, from an adult. Null when this row is a child's thumb.
            $table->unsignedTinyInteger('stars')->nullable();

            // 1 or -1, from a child on the wall. Null for an adult's row.
            $table->tinyInteger('thumbs')->nullable();

            // "Joey won't eat the sauce" belongs on the idea; this is the
            // shorter kind — "too spicy", "double it next time".
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['recipe_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ratings');
    }
};
