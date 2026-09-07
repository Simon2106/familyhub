<?php

namespace App\Services\Capture;

use App\Models\CaptureItem;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns the decoded JSON into DTOs.
 *
 * Separate from the API call so the same tolerance rules apply to a faked
 * response in tests as to a real one, and so a single malformed item does not
 * discard the other twenty-nine in a term calendar.
 */
class ExtractionParser
{
    /** @param array<string, mixed> $decoded */
    public static function fromArray(array $decoded, ?string $sourceLabel = null): ExtractionResult
    {
        $items = [];

        foreach ($decoded['items'] ?? [] as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $item = self::item($raw);

            if ($item !== null) {
                $items[] = $sourceLabel === null ? $item : $item->from($sourceLabel);
            }
        }

        $summary = isset($decoded['summary']) && is_string($decoded['summary'])
            ? trim($decoded['summary'])
            : null;

        return new ExtractionResult($items, $summary ?: null);
    }

    /** @param array<string, mixed> $raw */
    protected static function item(array $raw): ?ExtractedItem
    {
        $title = trim((string) ($raw['title'] ?? ''));

        if ($title === '') {
            return null;
        }

        $type = in_array($raw['type'] ?? null, CaptureItem::TYPES, strict: true) ? $raw['type'] : 'event';

        $allDay = (bool) ($raw['all_day'] ?? false);
        $start = self::date($raw['start'] ?? null);
        $end = self::date($raw['end'] ?? null);

        // A date with no time is an all-day item whatever the flag says.
        if ($start !== null && self::isDateOnly($raw['start'] ?? null)) {
            $allDay = true;
        }

        if ($start !== null && $end !== null && $end->lessThan($start)) {
            $end = null;
        }

        return new ExtractedItem(
            type: $type,
            title: mb_substr($title, 0, 250),
            startAt: $start,
            endAt: $end,
            allDay: $allDay,
            location: self::text($raw['location'] ?? null),
            notes: self::text($raw['notes'] ?? null),
            memberHint: self::text($raw['member_hint'] ?? null),
            forEventTitle: self::text($raw['for_event'] ?? null),
            confidence: max(0, min(100, (int) ($raw['confidence'] ?? 0))),
        );
    }

    /**
     * Parsed in the household's zone, because the model is told to answer in
     * local time; the UtcDateTime cast converts on the way to the database.
     */
    protected static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse(trim($value), config('familyhub.timezone'));
        } catch (Throwable) {
            return null;
        }

        // A model that hallucinates a year centuries out should not put an
        // event on the wall; treat it as undated instead.
        return $parsed->year >= 2000 && $parsed->year <= 2100 ? $parsed : null;
    }

    protected static function isDateOnly(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1;
    }

    protected static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }
}
