<?php

namespace App\Services\Schools;

use App\Models\BankHoliday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * England and Wales bank holidays.
 *
 * GOV.UK publishes them as JSON and that is the authority — it is the only
 * thing that knows about the one-offs, the coronations and jubilees and state
 * funerals that no rule can predict.
 *
 * The fallback is computed rather than a typed-in list. Working the dates out
 * by hand got two of the three Easters wrong on the first attempt, which is a
 * fair account of how well people do at this; the rules, by contrast, are four
 * lines and have not changed in decades.
 */
class BankHolidays
{
    public const FEED = 'https://www.gov.uk/bank-holidays.json';

    /** GOV.UK's key for England and Wales. */
    public const DIVISION = 'england-and-wales';

    /** How far ahead the computed fallback bothers to go. */
    public const FALLBACK_YEARS = 3;

    /**
     * Fill in from the rules only, without reaching out.
     *
     * For the migration: a migration that needs the internet is a migration
     * that fails on a laptop on a train.
     */
    public function seed(): int
    {
        return $this->store($this->computed(), 'computed', onlyMissing: true);
    }

    /** @return int how many dates are now known */
    public function sync(): int
    {
        $fromGov = $this->fromGovUk();

        if ($fromGov !== null) {
            return $this->store($fromGov, 'gov');
        }

        // Only fill gaps: a date GOV.UK has already confirmed must not be
        // overwritten by a rule that cannot know about one-offs.
        return $this->store($this->computed(), 'computed', onlyMissing: true);
    }

    /**
     * @return array<string, string>|null date => title, or null if unreachable
     */
    public function fromGovUk(): ?array
    {
        try {
            $response = Http::timeout(15)->connectTimeout(6)->acceptJson()->get(self::FEED);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $events = $response->json(self::DIVISION.'.events');

        if (! is_array($events) || $events === []) {
            return null;
        }

        $dates = [];

        foreach ($events as $event) {
            if (is_array($event) && filled($event['date'] ?? null)) {
                $dates[(string) $event['date']] = (string) ($event['title'] ?? 'Bank holiday');
            }
        }

        return $dates === [] ? null : $dates;
    }

    /**
     * The rules, for when GOV.UK cannot be reached.
     *
     * Cannot know about one-off holidays, which is exactly why it is second.
     *
     * @return array<string, string> date => title
     */
    public function computed(?int $fromYear = null, ?int $years = null): array
    {
        $fromYear ??= (int) CarbonImmutable::now()->year;
        $years ??= self::FALLBACK_YEARS;
        $dates = [];

        for ($year = $fromYear; $year < $fromYear + $years; $year++) {
            // easter_days(), not easter_date(): the latter returns a timestamp
            // and lands a day early in some years and timezones — it put Easter
            // 2026 on the 4th of April where GOV.UK says the 5th. This counts
            // days from 21 March and cannot drift.
            $easter = CarbonImmutable::create($year, 3, 21)->addDays(easter_days($year));

            $fixed = [
                'New Year’s Day' => CarbonImmutable::create($year, 1, 1),
                'Good Friday' => $easter->subDays(2),
                'Easter Monday' => $easter->addDay(),
                'Early May bank holiday' => CarbonImmutable::parse("first monday of may {$year}"),
                'Spring bank holiday' => CarbonImmutable::parse("last monday of may {$year}"),
                'Summer bank holiday' => CarbonImmutable::parse("last monday of august {$year}"),
                'Christmas Day' => CarbonImmutable::create($year, 12, 25),
                'Boxing Day' => CarbonImmutable::create($year, 12, 26),
            ];

            foreach ($fixed as $title => $date) {
                // Christmas and New Year move to the next free weekday when
                // they land at a weekend; Easter and the Mondays never do.
                $observed = str_contains($title, 'Monday') || str_contains($title, 'Friday')
                    ? $date
                    : $this->nextFreeWeekday($date, $dates);

                $dates[$observed->toDateString()] = $title
                    .($observed->ne($date) ? ' (substitute day)' : '');
            }
        }

        ksort($dates);

        return $dates;
    }

    /** @param array<string, string> $taken */
    protected function nextFreeWeekday(CarbonImmutable $date, array $taken): CarbonImmutable
    {
        while ($date->isWeekend() || isset($taken[$date->toDateString()])) {
            $date = $date->addDay();
        }

        return $date;
    }

    /** @param array<string, string> $dates */
    protected function store(array $dates, string $source, bool $onlyMissing = false): int
    {
        foreach ($dates as $on => $title) {
            $existing = BankHoliday::where('on', $on)->first();

            if ($existing && ($onlyMissing || $existing->source === 'gov' && $source !== 'gov')) {
                continue;
            }

            BankHoliday::updateOrCreate(['on' => $on], ['title' => $title, 'source' => $source]);
        }

        return BankHoliday::count();
    }
}
