<?php

namespace App\Services\CalDav;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\DateTimeParser;
use Sabre\VObject\Reader;

/**
 * Translates between iCalendar VEVENTs and our Event rows.
 *
 * A single .ics resource can hold several VEVENTs: the master event plus one
 * component per modified occurrence, each carrying a RECURRENCE-ID. They share
 * a UID, which is why event identity is (calendar_id, external_id, recurrence_id)
 * rather than UID alone.
 */
class EventMapper
{
    /**
     * Every VEVENT in one calendar resource, as attribute arrays.
     *
     * @return list<array<string, mixed>>
     */
    public function fromIcs(string $ics): array
    {
        $parsed = Reader::read($ics, Reader::OPTION_FORGIVING);

        if (! $parsed instanceof VCalendar) {
            return [];
        }

        $out = [];

        foreach ($parsed->VEVENT ?? [] as $vevent) {
            $attributes = $this->fromVEvent($vevent);

            if ($attributes !== null) {
                $out[] = $attributes;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function fromVEvent(VEvent $vevent): ?array
    {
        $uid = (string) ($vevent->UID ?? '');

        if ($uid === '') {
            return null;
        }

        $start = $vevent->DTSTART;

        if ($start === null) {
            return null;
        }

        // A DATE (rather than DATE-TIME) value is what makes an event all-day.
        $allDay = ! $start->hasTime();

        $startAt = CarbonImmutable::instance($start->getDateTime());
        $endAt = $this->endOf($vevent, $startAt, $allDay);

        return [
            'external_id' => $uid,
            'recurrence_id' => $this->recurrenceId($vevent),
            'title' => (string) ($vevent->SUMMARY ?? '(no title)'),
            // Stored UTC by the model cast; all-day events are pinned to the
            // household's day rather than shifted by a zone conversion.
            'start_at' => $allDay ? $startAt->startOfDay() : $startAt->setTimezone('UTC'),
            'end_at' => $allDay ? $endAt->endOfDay() : $endAt->setTimezone('UTC'),
            'all_day' => $allDay,
            'location' => $this->text($vevent->LOCATION),
            'notes' => $this->text($vevent->DESCRIPTION),
            'rrule' => $this->text($vevent->RRULE),
            'status' => $this->status($vevent),
            'source_hash' => hash('sha256', $vevent->serialize()),
        ];
    }

    /**
     * Build a complete .ics resource for an event.
     *
     * Emitted in UTC so the file carries no VTIMEZONE and cannot be
     * misinterpreted by whatever client reads it next.
     */
    public function toIcs(Event $event): string
    {
        $calendar = new VCalendar([
            'PRODID' => '-//FamilyHub//EN',
            'VERSION' => '2.0',
            'CALSCALE' => 'GREGORIAN',
        ]);

        $vevent = $calendar->add('VEVENT', [
            'UID' => $event->external_id,
            'SUMMARY' => $event->title,
            'DTSTAMP' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC'))),
        ]);

        if ($event->all_day) {
            // All-day DTEND is exclusive: a one-day event ends on the next day.
            $vevent->add('DTSTART', $event->start_at->toDateTime(), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $event->end_at->addDay()->startOfDay()->toDateTime(), ['VALUE' => 'DATE']);
        } else {
            $vevent->add('DTSTART', $event->start_at->setTimezone('UTC')->toDateTime());
            $vevent->add('DTEND', $event->end_at->setTimezone('UTC')->toDateTime());
        }

        foreach (['LOCATION' => $event->location, 'DESCRIPTION' => $event->notes] as $name => $value) {
            if (filled($value)) {
                $vevent->add($name, $value);
            }
        }

        if (filled($event->rrule)) {
            $vevent->add('RRULE', $event->rrule);
        }

        if (filled($event->recurrence_id)) {
            $vevent->add('RECURRENCE-ID', $event->recurrence_id);
        }

        $vevent->add('STATUS', strtoupper($event->status ?: 'confirmed'));

        return $calendar->serialize();
    }

    /** The filename a new event is stored under inside the calendar collection. */
    public function resourceName(string $uid): string
    {
        // UIDs can contain characters that are illegal in a path segment.
        return rawurlencode($uid).'.ics';
    }

    public function newUid(): string
    {
        return (string) Str::uuid().'@familyhub';
    }

    protected function endOf(VEvent $vevent, CarbonImmutable $start, bool $allDay): CarbonImmutable
    {
        if ($vevent->DTEND !== null) {
            $end = CarbonImmutable::instance($vevent->DTEND->getDateTime());

            // An all-day DTEND is exclusive, so pull it back inside the event.
            return $allDay ? $end->subDay() : $end;
        }

        if ($vevent->DURATION !== null) {
            return CarbonImmutable::instance(
                $vevent->DTSTART->getDateTime()->add(
                    DateTimeParser::parseDuration((string) $vevent->DURATION)
                )
            );
        }

        // No DTEND and no DURATION: all-day means the single day, timed means
        // a zero-length instant (RFC 5545).
        return $allDay ? $start : $start;
    }

    protected function recurrenceId(VEvent $vevent): ?string
    {
        if ($vevent->{'RECURRENCE-ID'} === null) {
            return null;
        }

        // Normalised to UTC so the same occurrence always hashes identically,
        // whatever zone the writing client used.
        return CarbonImmutable::instance($vevent->{'RECURRENCE-ID'}->getDateTime())
            ->setTimezone('UTC')
            ->format('Ymd\THis\Z');
    }

    protected function status(VEvent $vevent): string
    {
        $status = strtolower($this->text($vevent->STATUS) ?? 'confirmed');

        return in_array($status, ['confirmed', 'tentative', 'cancelled'], strict: true)
            ? $status
            : 'confirmed';
    }

    protected function text(mixed $property): ?string
    {
        if ($property === null) {
            return null;
        }

        $value = trim((string) $property);

        return $value === '' ? null : $value;
    }
}
