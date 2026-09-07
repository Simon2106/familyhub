<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Routines: the same short list of things, every morning.
 *
 * Separate from chores on purpose. A chore is a job with a value attached; a
 * routine is a sequence a child is learning to run without being told, and
 * putting a price on brushing your teeth turns the wrong screw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            // morning | after_school | bedtime
            $table->string('kind', 24)->default('morning');
            $table->string('name');

            // When it is worth putting on the wall. Local wall-clock times, not
            // instants: "the morning routine runs until 8:30" means half past
            // eight in the kitchen, whatever the server thinks.
            $table->time('starts_at')->default('07:00:00');
            $table->time('ends_at')->default('08:30:00');

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['household_id', 'member_id', 'is_active']);
        });

        Schema::create('routine_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('icon', 16)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('routine_step_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_step_id')->constrained()->cascadeOnDelete();
            $table->date('on');
            $table->timestamp('completed_at');
            $table->timestamps();

            // A routine resets every day, and one tick per step per day is the
            // whole of its state.
            $table->unique(['routine_step_id', 'on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_step_completions');
        Schema::dropIfExists('routine_steps');
        Schema::dropIfExists('routines');
    }
};
