<?php

use App\Models\Household;
use App\Services\Calendar\OccurrenceStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every actual dated instance of every event.
 *
 * The sync stores one row per VEVENT, which for a repeating event is one row
 * carrying an RRULE and sitting at its first occurrence. Every reader in the
 * app then asked "what starts between these two dates", and got the answer
 * "football, once, in September" — a weekly training appeared on one day of
 * the month grid and nowhere the week after.
 *
 * Expanding on read would mean doing it in eight places, each subtly
 * differently. So it is done once, at sync, into here: one row per occurrence,
 * carrying the fields a reader needs to draw it without a join, and pointing
 * at whichever Event row a person would be editing if they tapped it — the
 * master for an ordinary occurrence, the override for an edited one.
 *
 * A window rather than all of time, because "FREQ=DAILY" with no UNTIL is a
 * perfectly ordinary thing for a calendar to contain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // The dates struck out of a series. Never stored until now, which
            // meant a cancelled week could not be represented at all.
            $table->text('exdate')->nullable()->after('rrule');
        });

        Schema::create('event_occurrences', function (Blueprint $table) {
            $table->id();

            /** The row a tap should open: the master, or the override. */
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();

            /** The series this belongs to, so a rebuild can clear it cheaply. */
            $table->foreignId('series_event_id')->constrained('events')->cascadeOnDelete();

            // Denormalised so the wall can draw a week without touching events.
            $table->foreignId('calendar_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('location')->nullable();

            /** The occurrence this stands in for, when it has been edited. */
            $table->dateTime('original_starts_at')->nullable();
            $table->boolean('is_override')->default(false);

            $table->timestamps();

            // One occurrence per series per moment. An override that moves to
            // a time another occurrence already holds replaces it rather than
            // doubling it.
            $table->unique(['series_event_id', 'starts_at'], 'occurrences_series_start_unique');
            $table->index(['calendar_id', 'starts_at']);
            $table->index(['starts_at', 'ends_at']);
        });

        $this->backfill();
    }

    /**
     * Fill the table before anything reads it.
     *
     * Every calendar view now asks event_occurrences rather than events, so
     * between this migration and the first sync the wall would be blank —
     * which is a far worse bug than the one being fixed. Done here so the
     * deploy is complete the moment it finishes.
     */
    protected function backfill(): void
    {
        if (! class_exists(OccurrenceStore::class)) {
            return;
        }

        $store = app(OccurrenceStore::class);

        Household::query()->each(function ($household) use ($store) {
            try {
                $store->rebuildHousehold($household);
            } catch (Throwable $e) {
                // A household that cannot be expanded must not block the
                // deploy; the nightly command will try again.
                report($e);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_occurrences');

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('exdate');
        });
    }
};
