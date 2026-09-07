<?php

namespace App\Services\CalDav;

use App\Exceptions\CalDavException;
use App\Models\Calendar;
use App\Models\Event;
use App\Services\Attribution\EventAttributor;

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

    public function delete(Event $event): void
    {
        if ($event->href !== null) {
            $this->client->delete($event->href, ifMatch: $event->etag);
        }

        $event->delete();
    }

    protected function push(Event $event, ?string $ifMatch = null, bool $ifNoneMatch = false): void
    {
        // Persist first, flagged as unpushed, so a network failure leaves a
        // record to retry rather than losing what the user typed.
        $event->needs_push = true;
        $event->save();

        $etag = $this->client->put(
            $event->href,
            $this->mapper->toIcs($event),
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
            'source_hash' => hash('sha256', $this->mapper->toIcs($event)),
        ])->save();

        // Re-read who the event is about; a retitled event may concern
        // different people now. Skipped for events assigned by hand.
        $this->attributor->apply($event);
    }
}
