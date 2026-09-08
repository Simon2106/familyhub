<?php

namespace App\Services\Search;

use Carbon\CarbonImmutable;

/**
 * What somebody typed, split into words and a period.
 *
 * "dentist march" is two different questions in one box: which thing, and
 * roughly when. Pulling the when out means the words can be matched plainly
 * and the dates can narrow the answer, rather than "march" having to appear in
 * an event title before it counts for anything.
 */
class SearchQuery
{
    /** @param list<string> $terms */
    protected function __construct(
        public readonly string $raw,
        public readonly array $terms,
        public readonly ?CarbonImmutable $from = null,
        public readonly ?CarbonImmutable $to = null,
        public readonly ?string $period = null,
    ) {}

    public static function parse(string $raw, CarbonImmutable $today): self
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($raw))) ?: [];
        $words = array_values(array_filter($words));

        [$from, $to, $period, $rest] = self::period($words, $today);

        return new self(
            raw: trim($raw),
            terms: array_values(array_filter($rest, fn (string $w) => mb_strlen($w) >= 2)),
            from: $from,
            to: $to,
            period: $period,
        );
    }

    public function isEmpty(): bool
    {
        return $this->terms === [] && $this->from === null;
    }

    public function hasDates(): bool
    {
        return $this->from !== null && $this->to !== null;
    }

    /** Does a date fall in the asked-for period? Everything does if none was. */
    public function covers(?CarbonImmutable $date): bool
    {
        if (! $this->hasDates()) {
            return true;
        }

        // Something undated cannot be in March, so it drops out once a period
        // has been named.
        if ($date === null) {
            return false;
        }

        return $date->toDateString() >= $this->from->toDateString()
            && $date->toDateString() <= $this->to->toDateString();
    }

    /**
     * @param  list<string>  $words
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable, 2: ?string, 3: list<string>}
     */
    protected static function period(array $words, CarbonImmutable $today): array
    {
        $months = [
            'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
            'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9, 'october' => 10,
            'oct' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
        ];

        $rest = [];
        $from = $to = null;
        $period = null;

        for ($i = 0; $i < count($words); $i++) {
            $word = $words[$i];
            $next = $words[$i + 1] ?? null;

            // "this week", "next week", "last week"
            if (in_array($word, ['this', 'next', 'last'], true) && in_array($next, ['week', 'month'], true)) {
                $unit = $next === 'week' ? 'Week' : 'Month';
                $anchor = match ($word) {
                    'next' => $today->{'add'.$unit}(),
                    'last' => $today->{'sub'.$unit}(),
                    default => $today,
                };

                $from = $unit === 'Week' ? $anchor->startOfWeek() : $anchor->startOfMonth();
                $to = $unit === 'Week' ? $anchor->endOfWeek() : $anchor->endOfMonth();
                $period = "{$word} {$next}";
                $i++;

                continue;
            }

            if ($word === 'today') {
                [$from, $to, $period] = [$today, $today, 'today'];

                continue;
            }

            if ($word === 'tomorrow') {
                [$from, $to, $period] = [$today->addDay(), $today->addDay(), 'tomorrow'];

                continue;
            }

            if (isset($months[$word])) {
                // The coming one: "dentist march" in November means next March.
                $year = $months[$word] < $today->month ? $today->year + 1 : $today->year;
                $month = CarbonImmutable::create($year, $months[$word], 1);

                $from = $month->startOfMonth();
                $to = $month->endOfMonth();
                $period = $month->format('F Y');

                continue;
            }

            if (preg_match('/^(20\d{2})$/', $word, $m)) {
                $year = CarbonImmutable::create((int) $m[1], 1, 1);
                $from = $year->startOfYear();
                $to = $year->endOfYear();
                $period = $m[1];

                continue;
            }

            $rest[] = $word;
        }

        return [$from, $to, $period, $rest];
    }
}
