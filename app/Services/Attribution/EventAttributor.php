<?php

namespace App\Services\Attribution;

use App\Models\Calendar;
use App\Models\Event;
use App\Models\Household;

/**
 * Applies member attribution to events.
 *
 * Runs on sync and on save, and can be re-run over everything from the admin
 * page after aliases or places change.
 */
class EventAttributor
{
    /** @var array<int, AttributionMatcher> keyed by household id */
    protected array $matchers = [];

    public function matcherFor(Household $household): AttributionMatcher
    {
        return $this->matchers[$household->id] ??= AttributionMatcher::forHousehold($household);
    }

    /** Drop cached matchers after aliases or places change. */
    public function forget(): void
    {
        $this->matchers = [];
    }

    /**
     * Attribute one event.
     *
     * @return bool whether the event's members changed
     */
    public function apply(Event $event, ?AttributionMatcher $matcher = null): bool
    {
        // A person has chosen the members by hand; later syncs must not undo that.
        if ($event->attributionIsManual()) {
            return false;
        }

        $calendar = $event->calendar;

        if (! $calendar) {
            return false;
        }

        $matcher ??= $this->matcherFor($calendar->account->household);

        $matched = $matcher->match($event->title, $event->location);

        // Nothing recognised in the title: fall back to whoever owns the
        // calendar, and failing that leave it as a household event.
        if ($matched === [] && $calendar->member_id !== null) {
            $matched = [$calendar->member_id => AttributionMatcher::REASON_CALENDAR];
        }

        $before = $event->members()->pluck('members.id')->sort()->values()->all();

        $event->members()->sync(
            collect($matched)->mapWithKeys(fn (string $reason, int $id) => [$id => ['reason' => $reason]])->all()
        );

        $after = collect(array_keys($matched))->sort()->values()->all();

        return $before !== $after;
    }

    /** @return int events whose attribution changed */
    public function applyToCalendar(Calendar $calendar): int
    {
        $matcher = $this->matcherFor($calendar->account->household);
        $changed = 0;

        $calendar->events()
            ->where('attribution', 'auto')
            ->with('calendar.account.household')
            ->chunkById(200, function ($events) use ($matcher, &$changed) {
                foreach ($events as $event) {
                    $changed += $this->apply($event, $matcher) ? 1 : 0;
                }
            });

        return $changed;
    }

    /** Re-run over the whole household, e.g. after editing aliases. */
    public function applyToHousehold(Household $household): int
    {
        $this->forget();

        $changed = 0;

        foreach ($household->calendarAccounts()->with('calendars')->get() as $account) {
            foreach ($account->calendars as $calendar) {
                $changed += $this->applyToCalendar($calendar);
            }
        }

        return $changed;
    }

    /**
     * Record a hand-picked set of members, and stop attribution touching this
     * event again.
     *
     * @param  list<int>  $memberIds
     */
    public function setManually(Event $event, array $memberIds): void
    {
        $event->members()->sync(
            collect($memberIds)->mapWithKeys(fn (int $id) => [$id => ['reason' => 'manual']])->all()
        );

        $event->forceFill(['attribution' => 'manual'])->save();
    }

    /** Hand the event back to automatic attribution. */
    public function resetToAutomatic(Event $event): void
    {
        $event->forceFill(['attribution' => 'auto'])->save();

        $this->apply($event->refresh());
    }
}
