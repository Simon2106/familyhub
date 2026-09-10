<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reminders somebody built rather than a single lead time.
 *
 * "Fifteen minutes before everything" is the setting nobody wants: it is
 * either noise or silence, and the thing a family actually says is "tell me
 * the evening before anything of Sienna's at school". So a rule is who, what
 * and when, and a person has as many as they need.
 *
 * Per user, not per household: two parents want different things told to them
 * about the same calendar, and a shared rule would be an argument.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80);
            $table->boolean('is_active')->default(true);

            /** all | calendar | place | keyword | event */
            $table->string('scope', 16)->default('all');

            // Whichever one the scope names. Nulled rather than deleted with
            // the thing they point at: a rule whose calendar has gone should
            // stop matching, not take the rule with it.
            $table->foreignId('calendar_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('place_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('keyword', 80)->nullable();

            /** For a rule made about one event: does it follow the series. */
            $table->boolean('include_repeats')->default(false);

            /** Member ids. Empty means anyone — see ReminderRule::concerns(). */
            $table->json('members');

            /** When to tell them, as "before:15", "morning:07:00", "prev:19:00". */
            $table->json('times');

            /** A one-off made from an event, so the list can say which are which. */
            $table->boolean('is_one_off')->default(false);

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_rules');
    }
};
