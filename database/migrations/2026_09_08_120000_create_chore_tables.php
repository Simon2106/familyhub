<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chores and the days they fall on.
 *
 * A chore is the standing arrangement — "feed the cat, every day, 2 points".
 * An instance is one day's worth of it, and only exists once something has
 * happened to it: instances are created when a chore is ticked, not swept into
 * being every night. That keeps a fortnight away from home from leaving a
 * fortnight of accusing empty rows, and means no cron job stands between a
 * child and their chore list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            // Null means anybody's — a chore the household shares out.
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->string('icon', 16)->nullable();

            // daily | weekdays | days | weekly
            $table->string('recurrence', 16)->default('daily');

            // ISO weekdays (1 = Monday) for `days`, and the single chosen day
            // for `weekly`. Ignored by daily and weekdays.
            $table->json('days')->nullable();

            $table->unsignedSmallInteger('points')->default(0);

            // Some chores are taken on trust; some want an adult to look.
            $table->boolean('needs_approval')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // A chore added today should not appear on last Tuesday.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->timestamps();

            $table->index(['household_id', 'is_active']);
        });

        Schema::create('chore_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chore_id')->constrained()->cascadeOnDelete();

            // Denormalised: who it was for on the day, so reassigning a chore
            // does not silently rewrite who earned last week's points.
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();

            $table->date('on');

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_member_id')->nullable()->constrained('members')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_member_id')->nullable()->constrained('members')->nullOnDelete();

            // What the chore was worth on the day it was done. Changing a
            // chore's points later must not restate history.
            $table->unsignedSmallInteger('points')->default(0);

            $table->timestamps();

            // One instance per chore per day, which is what makes ticking
            // idempotent under two thumbs on the same screen.
            $table->unique(['chore_id', 'on']);
            $table->index(['member_id', 'on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chore_instances');
        Schema::dropIfExists('chores');
    }
};
