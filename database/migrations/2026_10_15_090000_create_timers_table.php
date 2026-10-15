<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timers that survive the browser.
 *
 * Stored as the moment they go off rather than as a countdown, so a Chromium
 * that restarts at ten past six comes back to a pasta timer with four minutes
 * left on it rather than to nothing. The client ticks locally between
 * resyncs; this row is the truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();

            $table->string('name')->nullable();

            /** What was asked for, so a paused timer can say "20 minutes". */
            $table->unsignedInteger('seconds');

            /** When it goes off. Null only while paused. */
            $table->timestamp('ends_at')->nullable();

            /** What was left at the moment it was paused. */
            $table->unsignedInteger('remaining_seconds')->nullable();

            /** Set when it rang, so the chime happens once however many tabs are open. */
            $table->timestamp('rang_at')->nullable();

            /** Dismissed timers stay for the record until the row is cleaned up. */
            $table->timestamp('dismissed_at')->nullable();

            /** voice | cook | manual — only ever used to explain itself. */
            $table->string('source')->default('manual');

            $table->timestamps();

            // Every read is "what is live for this household", in that order.
            $table->index(['household_id', 'dismissed_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timers');
    }
};
