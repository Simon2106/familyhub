<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fridge door.
 *
 * Short-lived, unstructured, and nobody's job: "back late Tuesday", "PE kit
 * in the wash", "Grandma rang". None of that is an event, a to-do or a chore,
 * and forcing it into one of those is how a wall calendar ends up with a
 * to-do list full of things nobody is going to tick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            // Whose it is, which is only ever used for the colour. A note
            // written at the wall by nobody in particular is still a note.
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();

            $table->string('body', 280);

            /** Optional. The day it stops being shown, inclusive. */
            $table->date('expires_on')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
