<?php

namespace App\Services\Bins;

use App\Models\BinCollection;
use Carbon\CarbonImmutable;

/**
 * A fortnightly bin round, described rather than subscribed to.
 *
 * Buckinghamshire publishes a PDF and nothing machine-readable, so the round
 * is written down once: which weekday, which bins every week, and which bins
 * alternate. It is a small enough description to be obviously right or
 * obviously wrong, which is worth a lot when the alternative is scraping a
 * calendar out of a rendered grid.
 */
class BinPattern
{
    /**
     * @param  int  $weekday  ISO weekday, 1 = Monday
     * @param  list<string>  $weekly  bins collected every week
     * @param  list<string>  $weekA  bins collected on the anchor week
     * @param  list<string>  $weekB  bins collected on the alternate week
     */
    public function __construct(
        public readonly int $weekday,
        public readonly CarbonImmutable $anchor,
        public readonly array $weekly = [],
        public readonly array $weekA = [],
        public readonly array $weekB = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $anchor = self::date($data['anchor'] ?? null);
        $weekday = (int) ($data['weekday'] ?? 0);

        if ($anchor === null || $weekday < 1 || $weekday > 7) {
            return null;
        }

        return new self(
            weekday: $weekday,
            // The anchor names which week is A, so it has to be a collection
            // day. Nudged rather than rejected: a date typed on a phone is
            // usually a day out, not meaningless.
            anchor: self::onWeekday($anchor, $weekday),
            weekly: self::kinds($data['weekly'] ?? []),
            weekA: self::kinds($data['week_a'] ?? []),
            weekB: self::kinds($data['week_b'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'weekday' => $this->weekday,
            'anchor' => $this->anchor->toDateString(),
            'weekly' => $this->weekly,
            'week_a' => $this->weekA,
            'week_b' => $this->weekB,
        ];
    }

    public function isUsable(): bool
    {
        return $this->weekly !== [] || $this->weekA !== [] || $this->weekB !== [];
    }

    /**
     * Which bins go out on a given collection day.
     *
     * @return list<string>
     */
    public function kindsOn(CarbonImmutable $date): array
    {
        if ($date->dayOfWeekIso !== $this->weekday) {
            return [];
        }

        $alternating = $this->isWeekA($date) ? $this->weekA : $this->weekB;

        return array_values(array_unique([...$this->weekly, ...$alternating]));
    }

    /** Parity from the anchor, which is by definition a week-A collection. */
    public function isWeekA(CarbonImmutable $date): bool
    {
        $weeks = (int) round($this->anchor->diffInDays($date->startOfDay(), false) / 7);

        // abs(), because parity is symmetric and PHP's modulo is not.
        return abs($weeks) % 2 === 0;
    }

    /**
     * Every collection day in a range, with what goes out.
     *
     * @return list<array{on: string, kinds: list<string>}>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        $date = self::onWeekday($from->startOfDay(), $this->weekday);

        // onWeekday looks backwards, so the first hit may predate the range.
        if ($date->lessThan($from->startOfDay())) {
            $date = $date->addWeek();
        }

        while (! $date->greaterThan($to)) {
            $kinds = $this->kindsOn($date);

            if ($kinds !== []) {
                $days[] = ['on' => $date->toDateString(), 'kinds' => $kinds];
            }

            $date = $date->addWeek();
        }

        return $days;
    }

    /** The nearest day on the right weekday, at or before the given one. */
    protected static function onWeekday(CarbonImmutable $date, int $weekday): CarbonImmutable
    {
        return $date->startOfDay()->subDays(($date->dayOfWeekIso - $weekday + 7) % 7);
    }

    protected static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value))) {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    protected static function kinds(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($v) => is_string($v) ? trim($v) : '', $values),
            fn (string $kind) => $kind !== '' && array_key_exists($kind, BinCollection::KINDS),
        )));
    }
}
