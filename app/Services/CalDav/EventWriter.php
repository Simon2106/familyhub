<?php

namespace App\Services\CalDav;

use App\Exceptions\CalDavException;
use App\Models\Calendar;
use App\Models\Event;
use App\Services\Attribution\EventAttributor;
use Carbon\CarbonImmutable;

/**
 * Pushes local changes back to iCloud.
 *
 * Writes are conditional: a create uses If-None-Match:* so it cannot clobber an
 * existing resource, and an update uses If-Match with the ETag we last saw so a
 * change made on someone's phone in the meantime is refused rather than
 * silently overwritten.
 */
class EventWriter
{
    public function __construct(
        protected CalDavClient $client,
        protected EventMapper $mapper,
        protected EventAttributor $attributor,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(Calendar $calendar, array $attributes): Event
    {
        if (! $calendar->is_writable) {
            throw new CalDavException("The calendar \"{$calendar->name}\" is read-only.");
        }

        $uid = $attributes['external_id'] ?? $this->mapper->newUid();

        $event = new Event($attributes);
        $event->calendar_id = $calendar->id;
        $event->external_id = $uid;
        $event->href = rtrim($calendar->external_id, '/').'/'.$this->mapper->resourceName($uid);

        $this->push($event, ifNoneMatch: true);

        return $event;
    }

    /** @param array<string, mixed> $attributes */
    public function update(Event $event, array $attributes): Event
    {
        $event->fill($attributes);

        $this->push($event, ifMatch: $event->etag);

        return $event;
    }

    /**
     * Change one occurrence of a repeating event, leaving the rest alone.
     *
     * iCloud expresses this as a second VEVENT sharing the UID and carrying a
     * RECURRENCE-ID naming the occurrence it stands in for. Both live in the
     * same resource, so the whole set is written at once.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateOccurrence(Event $master, CarbonImmutable $occurrenceStart, array $attributes): Event
    {
        if (! $master->repeats()) {
            return $this->update($master, $attributes);
        }

        $override = $this->overrideFor($master, $occurrenceStart);
        $length = $master->start_at->diffInSeconds($master->end_at);

        $override->fill($attributes);

        // An edit that did not say otherwise keeps the occurrence's own time
        // rather than inheriting the series' first.
        $override->start_at ??= $occurrenceStart;
        $override->end_at ??= $occurrenceStart->addSeconds((int) $length);

        $override->save();

        $this->pushSeries($master);

        return $override;
    }

    /**
     * Take one occurrence off the calendar without touching the rest.
     *
     * An EXDATE on the master, which is what "delete just this one" means in
     * iCalendar — and what the phone will do with it. Any override standing in
     * for that occurrence goes too, or the struck-out date would come back
     * wearing the override's clothes.
     */
    public function deleteOccurrence(Event $master, CarbonImmutable $occurrenceStart): void
    {
        if (! $master->repeats()) {
            $this->delete($master);

            return;
        }

        $master->seriesQuery()
            ->whereNotNull('recurrence_id')
            ->get()
            ->filter(fn (Event $o) => $this->sameMoment($o->recurrence_id, $occurrenceStart))
            ->each->delete();

        $master->forceFill([
            'exdate' => [
                ...(array) ($master->exdate ?? []),
                $occurrenceStart->utc()->toIso8601String(),
            ],
        ])->save();

        $this->pushSeries($master);
    }

    /** The whole series, or a single event. */
    public function delete(Event $event): void
    {
        // Deleting any row of a series deletes the resource, which is the
        // whole series — so the rest of it has to go locally too.
        $siblings = $event->seriesQuery()->get();

        if ($event->href !== null) {
            $this->client->delete($event->href, ifMatch: $event->etag);
        }

        $siblings->each->delete();
    }

    /**
     * Write the resource: master plus every override, in one PUT.
     *
     * The single most important line in this class. A resource is the whole
     * recurrence set, and a PUT replaces it — so pushing one VEVENT to a
     * series href silently deletes every exception the family had made.
     */
    protected function pushSeries(Event $master): void
    {
        $master->refresh();

        $overrides = $master->seriesQuery()->whereNotNull('recurrence_id')->get();
        $ics = $this->mapper->seriesToIcs($master, $overrides);

        $master->needs_push = true;
        $master->save();

        $etag = $this->client->put($master->href, $ics, ifMatch: $master->etag);

        $master->forceFill([
            'etag' => $etag,
            'needs_push' => false,
            'pushed_at' => now(),
            'source_hash' => hash('sha256', $ics),
        ])->save();

        foreach ($overrides as $override) {
            $override->forceFill(['etag' => $etag, 'needs_push' => false, 'pushed_at' => now()])->save();
        }

        $this->attributor->apply($master);
    }

    /** The override standing in for an occurrence, made if there is not one. */
    protected function overrideFor(Event $master, CarbonImmutable $occurrenceStart): Event
    {
        $existing = $master->seriesQuery()
            ->whereNotNull('recurrence_id')
            ->get()
            ->first(fn (Event $o) => $this->sameMoment($o->recurrence_id, $occurrenceStart));

        if ($existing) {
            return $existing;
        }

        $override = $master->replicate(['etag', 'source_hash', 'pushed_at', 'rrule', 'exdate']);

        $override->recurrence_id = $occurrenceStart->utc()->format('Y-m-d\\TH:i:s\\Z');
        $override->rrule = null;
        $override->exdate = null;
        $override->start_at = $occurrenceStart;
        $override->end_at = $occurrenceStart->addSeconds(
            (int) $master->start_at->diffInSeconds($master->end_at)
        );

        return $override;
    }

    protected function sameMoment(?string $recurrenceId, CarbonImmutable $moment): bool
    {
        if (blank($recurrenceId)) {
            return false;
        }

        try {
            return CarbonImmutable::parse($recurrenceId)->utc()->format('Y-m-d H:i')
                === $moment->utc()->format('Y-m-d H:i');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function push(Event $event, ?string $ifMatch = null, bool $ifNoneMatch = false): void
    {
        // Persist first, flagged as unpushed, so a network failure leaves a
        // record to retry rather than losing what the user typed.
        $event->needs_push = true;
        $event->save();

        // A master with exceptions must be written whole, or the PUT wipes
        // them: one resource holds the entire recurrence set.
        $ics = $event->isSeriesMaster() && $event->repeats()
            ? $this->mapper->seriesToIcs($event, $event->seriesQuery()->whereNotNull('recurrence_id')->get())
            : $this->mapper->toIcs($event);

        $etag = $this->client->put(
            $event->href,
            $ics,
            ifMatch: $ifMatch,
            ifNoneMatch: $ifNoneMatch,
        );

        // iCloud does not always return an ETag on PUT. When it does not, the
        // next sync will pick the real one up; leaving it null simply means the
        // following update is unconditional.
        $event->forceFill([
            'etag' => $etag,
            'needs_push' => false,
            'pushed_at' => now(),
            'source_hash' => hash('sha256', $ics),
        ])->save();

        // Re-read who the event is about; a retitled event may concern
        // different people now. Skipped for events assigned by hand.
        $this->attributor->apply($event);
    }
}
