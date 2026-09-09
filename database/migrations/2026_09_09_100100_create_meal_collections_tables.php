<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups of ideas the family makes up themselves.
 *
 * "Sunday roasts", "Freezer standbys", "Jenna's picks". Distinct from tags:
 * a tag describes what a meal *is*, a collection is a pile somebody made on
 * purpose, and the same idea belongs in as many of either as it likes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['household_id', 'name']);
        });

        Schema::create('meal_collection_recipe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();

            $table->unique(['meal_collection_id', 'recipe_id'], 'collection_recipe_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_collection_recipe');
        Schema::dropIfExists('meal_collections');
    }
};
