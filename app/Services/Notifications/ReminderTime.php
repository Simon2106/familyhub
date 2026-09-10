<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;

/**
 * One "when" on a rule.
 *
 * Three shapes, because a family means three different things by "remind me":
 * so long before it starts, on the morning of it, or the evening before. The
 * last two are clock times rather than offsets — "the night before" means
 * seven o'clock whether the match is at nine or at two — and trying to express
 * them as minutes is what makes reminder settings feel wrong.
 *
 * Stored as a short string so a rule's times stay legible in the database and
 * can be compared and de-duplicated without unpacking.
 */
class ReminderTime
{
    public const BEFORE = 'before';

    public const MORNING = 'morning';

    public const PREVIOUS_EVENING = 'prev';

    /** What the page offers. Anything else is a custom number of minutes. */
    public const PRESETS = [
        'before:15' => '15 minutes before',
        'before:60' => 'An hour before',
        'morning:07:00' => 'That morning at 07:00',
        'prev:19:00' => 'The evening before at 19:00',
    ];

    /** A week. Longer than this is a typo, not a reminder. */
    public const MAX_MINUTES = 10080;

    private function __construct(
        public readonly string $kind,
        /** Minutes, for BEFORE. */
        public readonly int $minutes = 0,
        /** "HH:MM", for the two clock kinds. */
        public readonly string $at = '',
    ) {}

    public static function before(int $minutes): self
    {
        return new self(self::BEFORE, max(0, min($minutes, self::MAX_MINUTES)));
    }

    /** Parses "before:15", "morning:07:00", "prev:19:00". Null if it is none of those. */
    public static function parse(string $value): ?self
    {
        [$kind, $rest] = array_pad(explode(':', trim($value), 2), 2, '');

        if ($kind === self::BEFORE) {
            return is_numeric($rest) ? self::before((int) $rest) : null;
        }

        if (! in_array($kind, [self::MORNING, self::PREVIOUS_EVENING], true)) {
            return null;
        }

        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $rest)) {
            return null;
        }

        return new self($kind, at: $rest);
    }

    /**
     * @param  list<string>  $values
     * @return list<ReminderTime>
     */
    public static function list(array $values): array
    {
        $times = [];
        $seen = [];

        foreach ($values as $value) {
            $time = is_string($value) ? self::parse($value) : null;

            if (! $time || in_array($time->toString(), $seen, true)) {
                continue;
            }

            $seen[] = $time->toString();
            $times[] = $time;
        }

        // Earliest warning first, so a rule reads the way somebody would say it.
        usort($times, fn (self $a, self $b) => $b->roughLeadMinutes() <=> $a->roughLeadMinutes());

        return $times;
    }

    public function toString(): string
    {
        return $this->kind === self::BEFORE
            ? self::BEFORE.':'.$this->minutes
            : $this->kind.':'.$this->at;
    }

    /**
     * The moment to tell them about an event starting at $startsAt.
     *
     * The clock kinds are worked out in the household's own timezone, because
     * "that morning" is a thing about their kitchen and not about UTC.
     */
    public function momentFor(CarbonImmutable $startsAt, string $timezone): CarbonImmutable
    {
        if ($this->kind === self::BEFORE) {
            return $startsAt->subMinutes($this->minutes);
        }

        [$hour, $minute] = array_map('intval', explode(':', $this->at));

        $local = $startsAt->timezone($timezone);
        $day = $this->kind === self::MORNING ? $local : $local->subDay();

        return $day->setTime($hour, $minute)->utc();
    }

    /** Only for ordering and for capping how far ahead to look. */
    public function roughLeadMinutes(): int
    {
        return match ($this->kind) {
            self::BEFORE => $this->minutes,
            // Worst case: an event at one minute past midnight.
            self::MORNING => 24 * 60,
            default => 48 * 60,
        };
    }

    /** How it reads on the page, and in the body of the notification. */
    public function label(): string
    {
        if (isset(self::PRESETS[$this->toString()])) {
            return self::PRESETS[$this->toString()];
        }

        if ($this->kind === self::BEFORE) {
            return $this->minutes === 0
                ? 'As it starts'
                : $this->humanMinutes().' before';
        }

        return ($this->kind === self::MORNING ? 'That morning at ' : 'The evening before at ').$this->at;
    }

    protected function humanMinutes(): string
    {
        if ($this->minutes % 1440 === 0) {
            $days = intdiv($this->minutes, 1440);

            return $days.' '.($days === 1 ? 'day' : 'days');
        }

        if ($this->minutes % 60 === 0) {
            $hours = intdiv($this->minutes, 60);

            return $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        return $this->minutes.' minutes';
    }
}
