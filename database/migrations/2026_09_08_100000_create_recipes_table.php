<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The recipe box.
 *
 * A saved meal idea, however it arrived: a link, a pasted block of text, an
 * Instagram caption, or a photograph of a cookbook page. Extraction fills in
 * the structured parts; none of them are required, because a meal idea with
 * nothing but a title and a link is still worth keeping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->string('title');

            // url | text | photo | share
            $table->string('source_kind', 16)->default('text');
            $table->string('source_url', 2048)->nullable();

            // Said on the card when the link could not be opened, so a thin
            // recipe reads as "we could only see the caption", not as a bug.
            $table->string('source_note')->nullable();

            $table->string('hero_image_url', 2048)->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_disk', 32)->nullable();

            $table->unsignedSmallInteger('servings')->nullable();

            // [{quantity: float|null, unit: string|null, item: string, note: string|null}]
            $table->json('ingredients')->nullable();
            $table->json('steps')->nullable();
            $table->json('tags')->nullable();

            $table->boolean('is_favourite')->default(false);

            // pending | ready | failed — the box shows a preview card while
            // the model is still reading, the same as the review inbox.
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();

            // Whatever we had to work from, kept so a failed import can be
            // retried without asking anyone to share it again.
            $table->longText('raw_text')->nullable();

            $table->timestamps();

            $table->index(['household_id', 'status']);
            $table->index(['household_id', 'is_favourite']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
