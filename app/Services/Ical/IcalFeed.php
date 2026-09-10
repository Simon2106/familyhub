<?php

namespace App\Services\Ical;

use App\Exceptions\IcalException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Reads a subscribed calendar — bin collections, school terms — into dates.
 *
 * Deliberately narrow: it answers "what days does this feed say something
 * happens on", and nothing else. Recurrence, alarms and attendees are somebody
 * else's problem; a council telling you the bins go out on Tuesday does not
 * need the machinery Phase 2 needed for a real calendar.
 */
class IcalFeed
{
    /** Councils and schools publish small files. Anything larger is a mistake. */
    public const MAX_BYTES = 5_000_000;

    /** @return Collection<int, IcalEntry> */
    public function fetch(string $url): Collection
    {
        return $this->parse($this->download($url));
    }

    public function download(string $url): string
    {
        $url = $this->normalise($url);

        try {
            $response = Http::timeout(20)
                ->connectTimeout(8)
                ->withHeaders(['User-Agent' => 'FamilyHub/1.0 (+calendar-subscription)'])
                ->get($url);
        } catch (Throwable $e) {
            throw new IcalException('That calendar could not be reached: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new IcalException("That calendar answered {$response->status()}.");
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            throw new IcalException('That calendar is far larger than a calendar should be.');
        }

        if (! str_contains($body, 'BEGIN:VCALENDAR')) {
            // Almost always a login page or an HTML "here is your calendar" page.
            throw new IcalException('That address did not return a calendar. Check it is the subscription link (.ics), not the web page.');
        }

        return $body;
    }

    /** @return Collection<int, IcalEntry> */
    public function parse(string $ics): Collection
    {
        try {
            $calendar = Reader::read($ics, Reader::OPTION_FORGIVING);
        } catch (Throwable $e) {
            throw new IcalException('That calendar could not be read: '.$e->getMessage(), previous: $e);
        }

        $entries = collect();

        foreach ($calendar->VEVENT ?? [] as $event) {
            $entry = $this->toEntry($event);

            if ($entry) {
                $entries->push($entry);
            }
        }

        return $entries->sortBy(fn (IcalEntry $e) => $e->startsOn->toDateString())->values();
    }

    protected function toEntry(mixed $event): ?IcalEntry
    {
        $start = $this->dateOf($event->DTSTART ?? null);

        if ($start === null) {
            return null;
        }

        // An all-day DTEND is exclusive — a one-day event ends the next
        // morning — so a naive read makes every term a day too long.
        $end = $this->dateOf($event->DTEND ?? null);
        $end = $end === null ? $start : $this->lastDay($event, $start, $end);

        $summary = trim((string) ($event->SUMMARY ?? ''));

        // A DATE value has no T in it; a DATE-TIME does. That is the whole
        // distinction, and it is the feed's own rather than a guess.
        $allDay = ! str_contains((string) ($event->DTSTART ?? ''), 'T');

        return new IcalEntry(
            uid: trim((string) ($event->UID ?? '')) ?: sha1($summary.$start->toDateString()),
            summary: $summary !== '' ? $summary : 'Untitled',
            startsOn: $start,
            endsOn: $end->lessThan($start) ? $start : $end,
            description: trim((string) ($event->DESCRIPTION ?? '')) ?: null,
            startsAt: $allDay ? null : $this->momentOf($event->DTSTART ?? null),
            endsAt: $allDay ? null : $this->momentOf($event->DTEND ?? null),
            allDay: $allDay,
            location: trim((string) ($event->LOCATION ?? '')) ?: null,
        );
    }

    protected function lastDay(mixed $event, CarbonImmutable $start, CarbonImmutable $end): CarbonImmutable
    {
        $isAllDay = ! str_contains((string) ($event->DTSTART ?? ''), 'T');

        return $isAllDay && $end->greaterThan($start) ? $end->subDay() : $end;
    }

    /** The moment, in UTC, as events are stored everywhere else in the app. */
    protected function momentOf(mixed $property): ?CarbonImmutable
    {
        if ($property === null) {
            return null;
        }

        try {
            return CarbonImmutable::instance($property->getDateTime())->utc();
        } catch (Throwable) {
            return null;
        }
    }

    protected function dateOf(mixed $property): ?CarbonImmutable
    {
        if ($property === null) {
            return null;
        }

        try {
            return CarbonImmutable::instance($property->getDateTime())->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /** Calendar apps hand out webcal:// links; they are https underneath. */
    protected function normalise(string $url): string
    {
        $url = trim($url);

        if (str_starts_with(strtolower($url), 'webcal://')) {
            $url = 'https://'.substr($url, 9);
        }

        if (! preg_match('#^https?://#i', $url)) {
            throw new IcalException('A calendar address has to start with https:// or webcal://.');
        }

        return $url;
    }
}
