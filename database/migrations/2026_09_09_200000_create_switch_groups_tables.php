<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups of switches, with their own idea of on and off.
 *
 * Deliberately kept here rather than made into Home Assistant groups or
 * scenes: the wall and the phone are the source of truth for how this
 * household thinks about its lamps, and a grouping that lives in HA is one
 * nobody can change from the kitchen.
 *
 * Each group turns on and off in its own way — instantly, or after a few
 * minutes — because "off in five" is the thing a family actually wants from a
 * bank of lamps at bedtime, and the two directions are rarely the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // 0 is instant. Minutes otherwise.
            $table->unsignedSmallInteger('on_delay')->default(0);
            $table->unsignedSmallInteger('off_delay')->default(0);

            /*
             * One pending action per group, which is what the tile shows: a
             * countdown and a Cancel. Tapping again replaces it rather than
             * stacking, the same as changing your mind about the oven timer.
             *
             * On the server rather than in the browser, so a wall that has
             * gone dark — or been unplugged — still turns the lamps off.
             */
            $table->string('pending_direction', 3)->nullable();
            $table->timestamp('pending_fires_at')->nullable();

            /*
             * Schedules. Null trigger means that direction is not scheduled;
             * 'time' uses the column beside it, and sunrise/sunset come from
             * Home Assistant's own sun.sun entity, so the times move with the
             * year without anybody editing anything.
             */
            $table->string('on_trigger', 8)->nullable();
            $table->time('on_time')->nullable();
            $table->string('off_trigger', 8)->nullable();
            $table->time('off_time')->nullable();

            // ISO days, 1 = Monday. Empty means every day.
            $table->json('days')->nullable();

            // So a schedule fires once a day and not once a minute.
            $table->date('on_fired_on')->nullable();
            $table->date('off_fired_on')->nullable();

            $table->timestamps();

            $table->unique(['household_id', 'name']);
        });

        Schema::create('switch_group_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('switch_group_id')->constrained()->cascadeOnDelete();

            // The HA entity id, and the name it had when it was chosen — so a
            // group still reads sensibly when the Pi is unreachable.
            $table->string('entity_id', 191);
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['switch_group_id', 'entity_id'], 'group_entity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('switch_group_entities');
        Schema::dropIfExists('switch_groups');
    }
};
