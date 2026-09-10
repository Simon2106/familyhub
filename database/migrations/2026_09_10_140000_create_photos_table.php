<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The photographs the wall shows when nobody is using it.
 *
 * A table rather than a folder listing, because the things a family wants to
 * say about a photograph — a caption, "not that one" — have nowhere to live in
 * a filename. Loose files already in the folder are adopted on the next sync,
 * so nothing anybody has already put there is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            // upload | icloud | folder
            $table->string('source', 16)->default('upload');

            $table->string('disk', 32);
            $table->string('path');

            // iCloud's own id for the asset, so a re-sync recognises what it
            // has already fetched rather than downloading the album again.
            $table->string('external_id', 191)->nullable();

            $table->string('caption')->nullable();

            // When the photograph was taken, where the album says. Recent ones
            // are favoured, because a wall showing this summer beats one
            // showing a random decade.
            $table->timestamp('taken_at')->nullable();

            // "Not that one." Kept rather than deleted: a photograph hidden by
            // one person is not one the next sync should quietly bring back.
            $table->boolean('is_hidden')->default(false);

            $table->timestamps();

            $table->unique(['household_id', 'disk', 'path']);
            $table->index(['household_id', 'is_hidden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};
