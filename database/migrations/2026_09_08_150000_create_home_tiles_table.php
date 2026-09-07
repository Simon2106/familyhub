<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The entities a household has chosen to put on the wall.
 *
 * Home Assistant knows about hundreds of things; a kitchen wall wants about
 * twelve. This is the shortlist, with the room remembered alongside so the
 * Home tab can group without asking HA again on every render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_tiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->string('entity_id');
            $table->string('domain', 32);

            // What HA calls it, and what the household calls it. The override
            // matters: "Sonoff 0x00124b" is not a name anyone taps twice.
            $table->string('name');
            $table->string('label')->nullable();

            // Snapshotted from HA's area registry. Re-read when the picker is
            // opened, so a room renamed in HA is picked up on the next visit.
            $table->string('area')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['household_id', 'entity_id']);
            $table->index(['household_id', 'area']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_tiles');
    }
};
