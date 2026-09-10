<?php

namespace App\Services\Countdowns;

use App\Models\Countdown;
use App\Models\Household;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How many sleeps until the things worth counting.
 *
 * Two sources, deliberately different in kind. A countdown is a row somebody
 * made — a holiday, a match, a visit — and it goes when the day does. A
 * birthday is a property of a person and comes round again by itself, so
 * nobody has to remember to add next year's.
 */
class CountdownBoard
{
    /** How far ahead is still worth counting. Beyond this it is just a date. */
    public const HORIZON_DAYS = 365;

    /**
     * The soonest few, today's included, with anything past dropped.
     *
     * @return Collection<int, CountdownEntry>
     */
    public function upcoming(?Household $household = null, int $limit = 3): Collection
    {
        $household ??= Household::current();
        $today = $household->todayLocal();
        $horizon = $today->addDays(self::HORIZON_DAYS);

        return $this->made($household, $today, $horizon)
            ->merge($this->birthdays($household, $today, $horizon))
            ->sortBy(fn (CountdownEntry $entry) => $entry->on->toDateString())
            ->take(max(0, $limit))
            ->values();
    }

    /** @return Collection<int, CountdownEntry> */
    protected function made(Household $household, CarbonImmutable $today, CarbonImmutable $horizon): Collection
    {
        return Countdown::query()
            ->where('household_id', $household->id)
            ->where('on', '>=', $today->toDateString())
            ->where('on', '<=', $horizon->toDateString())
            ->with('event')
            ->orderBy('on')
            ->get()
            ->toBase()
            ->map(fn (Countdown $countdown) => new CountdownEntry(
                key: 'countdown:'.$countdown->id,
                label: $countdown->label,
                on: CarbonImmutable::parse($countdown->on),
                today: $today,
                countdown: $countdown,
            ));
    }

    /**
     * Birthdays, at their next occurrence.
     *
     * The 29th of February is kept as the 1st of March in a common year:
     * somebody born then still has a birthday every year, and a countdown that
     * silently skipped three years in four would be a bug nobody could see.
     *
     * @return Collection<int, CountdownEntry>
     */
    protected function birthdays(Household $household, CarbonImmutable $today, CarbonImmutable $horizon): Collection
    {
        return $household->members()
            ->whereNotNull('birthday')
            ->get()
            // Plain, not Eloquent: an Eloquent collection assumes its contents
            // are models and reaches for getKey() the moment it is filtered.
            ->toBase()
            ->map(function (Member $member) use ($today, $horizon) {
                $next = $this->nextBirthday(CarbonImmutable::parse($member->birthday), $today);

                if ($next->greaterThan($horizon)) {
                    return null;
                }

                $turning = $this->turning($member, $next);

                return new CountdownEntry(
                    key: 'birthday:'.$member->id,
                    label: $member->name."'s birthday".($turning ? ' — '.$turning : ''),
                    on: $next,
                    today: $today,
                    member: $member,
                );
            })
            ->filter()
            ->values();
    }

    protected function nextBirthday(CarbonImmutable $born, CarbonImmutable $today): CarbonImmutable
    {
        $month = (int) $born->format('n');
        $day = (int) $born->format('j');

        for ($year = $today->year; $year <= $today->year + 1; $year++) {
            $on = $this->birthdayIn($year, $month, $day);

            if (! $on->lessThan($today)) {
                return $on;
            }
        }

        return $this->birthdayIn($today->year + 1, $month, $day);
    }

    protected function birthdayIn(int $year, int $month, int $day): CarbonImmutable
    {
        // 29 February in a common year: the 1st of March, rather than silently
        // becoming the 28th or vanishing.
        if ($month === 2 && $day === 29 && ! CarbonImmutable::create($year, 1, 1)->isLeapYear()) {
            return CarbonImmutable::create($year, 3, 1)->startOfDay();
        }

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }

    /** "turning 8", where the year is known and worth saying. */
    protected function turning(Member $member, CarbonImmutable $next): ?string
    {
        $born = CarbonImmutable::parse($member->birthday);

        // A year of 1900 or thereabouts is somebody who only entered the day.
        if ($born->year < 1901) {
            return null;
        }

        $age = $next->year - $born->year;

        return $age > 0 && $age < 120 ? 'turning '.$age : null;
    }
}
