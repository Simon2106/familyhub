<?php

namespace App\Services\Notifications;

use App\Models\Event;
use App\Models\Household;
use App\Models\Place;
use App\Models\ReminderRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Sabre\VObject\Recur\RRuleIterator;
use Throwable;

/**
 * What a set of rules would tell somebody, and when.
 *
 * One engine, used twice: the scheduler asks what is due right now, and the
 * rule editor asks what the next three would be. That is deliberate — a
 * preview computed by different code from the thing it previews is a preview
 * that eventually lies, and the whole point of showing it is to be believed.
 */
class ReminderEngine
{
    /** How far ahead a preview will look before giving up. */
    public const PREVIEW_DAYS = 120;

    /**
     * The slack the scheduler is given.
     *
     * It runs every minute; a minute that is missed — a deploy, a slow queue —
     * is caught by the next few rather than lost. The ledger is what stops
     * that becoming four copies of the same reminder.
     */
    public const CATCH_UP_MINUTES = 4;

    /**
     * Everything this person should be told right now.
     *
     * @return list<DueReminder>
     */
    public function due(User $user, Household $household, CarbonImmutable $now): array
    {
        $from = $now->startOfMinute()->subMinutes(self::CATCH_UP_MINUTES);
        $to = $now->endOfMinute();

        $due = [];

        foreach ($this->rules($user) as $rule) {
            foreach ($this->remindersFor($rule, $household, $from, $to, $now) as $reminder) {
                $due[] = $reminder;
            }
        }

        return $due;
    }

    /**
     * The next few this rule would produce, for showing before saving.
     *
     * Takes a rule that need not have been saved, which is the point: the
     * preview is of what is on the screen, not of what is in the database.
     *
     * @return list<DueReminder>
     */
    public function preview(ReminderRule $rule, Household $household, int $limit = 3, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $found = $this->remindersFor(
            $rule,
            $household,
            $now,
            $now->addDays(self::PREVIEW_DAYS),
            $now,
        );

        usort($found, fn (DueReminder $a, DueReminder $b) => $a->at <=> $b->at);

        return array_slice($found, 0, $limit);
    }

    /** @return Collection<int, ReminderRule> */
    public function rules(User $user): Collection
    {
        return ReminderRule::query()
            ->where('user_id', $user->id)
            ->active()
            ->with(['calendar', 'place', 'event'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->reject(fn (ReminderRule $rule) => $rule->isBroken())
            ->values();
    }

    /**
     * Every reminder this rule produces whose moment falls in the window.
     *
     * @return list<DueReminder>
     */
    protected function remindersFor(
        ReminderRule $rule,
        Household $household,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $now,
    ): array {
        $times = $rule->reminderTimes();

        if ($times === []) {
            return [];
        }

        $timezone = $household->displayTimezone();
        $lead = $rule->furthestLeadMinutes();

        // The events worth considering: anything starting between the earliest
        // moment this rule could still be talking about and the end of the
        // window plus its longest warning.
        $events = $this->candidates(
            $rule,
            $household,
            $from->subMinutes(5),
            $to->addMinutes($lead),
        );

        $out = [];

        foreach ($events as $event) {
            $occurrences = $this->occurrences($event, $from->subMinutes(5), $to->addMinutes($lead));

            // "This one, not the series." A repeating event picked without
            // ticking "and future repeats" means the next occurrence after
            // the rule was made, and only that one — otherwise the flag would
            // do nothing at all on the only kind of event it exists for.
            if ($rule->scope === 'event' && ! $rule->include_repeats) {
                $occurrences = $this->justTheNextOne($rule, $event, $occurrences);
            }

            foreach ($occurrences as $startsAt) {
                foreach ($times as $time) {
                    $at = $time->momentFor($startsAt, $timezone);

                    if ($at->lessThan($from) || $at->greaterThan($to)) {
                        continue;
                    }

                    // A reminder about something that has already started is
                    // not a reminder. Catches a rule made an hour too late.
                    if ($startsAt->lessThan($now->subMinutes(self::CATCH_UP_MINUTES))) {
                        continue;
                    }

                    $out[] = new DueReminder($rule, $event, $startsAt, $time, $at);
                }
            }
        }

        return $out;
    }

    /**
     * The single occurrence a "just this one" rule is about.
     *
     * The one the family would have been looking at when they asked: the
     * first from when the rule was made. An unsaved draft is being previewed
     * right now, so it uses now.
     *
     * @param  list<CarbonImmutable>  $occurrences
     * @return list<CarbonImmutable>
     */
    protected function justTheNextOne(ReminderRule $rule, Event $event, array $occurrences): array
    {
        if (blank($event->rrule)) {
            return $occurrences;
        }

        $since = $rule->created_at
            ? CarbonImmutable::parse($rule->created_at)->utc()
            : CarbonImmutable::now();

        foreach ($occurrences as $occurrence) {
            if ($occurrence->greaterThanOrEqualTo($since)) {
                return [$occurrence];
            }
        }

        return [];
    }

    /**
     * The events a rule could match, narrowed in SQL where it can be.
     *
     * @return Collection<int, Event>
     */
    protected function candidates(
        ReminderRule $rule,
        Household $household,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        $query = Event::query()
            ->notCancelled()
            ->whereHas('calendar', fn ($q) => $q
                ->where('is_visible', true)
                ->whereHas('account', fn ($a) => $a->where('household_id', $household->id)))
            ->with(['members', 'calendar']);

        // A repeating event's row sits at the first occurrence, which may be
        // years back, so the window cannot be applied to it in SQL.
        $query->where(fn ($q) => $q
            ->whereBetween('start_at', [$from, $to])
            ->orWhereNotNull('rrule'));

        match ($rule->scope) {
            'calendar' => $query->where('calendar_id', $rule->calendar_id),
            'event' => $rule->include_repeats && $rule->event
                ? $query->where('external_id', $rule->event->external_id)
                    ->where('calendar_id', $rule->event->calendar_id)
                : $query->whereKey($rule->event_id),
            'keyword' => $query->where('title', 'like', '%'.$this->escape((string) $rule->keyword).'%'),
            default => null,
        };

        $events = $query->limit(500)->get();

        if ($rule->scope === 'place') {
            $events = $events->filter(fn (Event $e) => $this->atPlace($e, $rule->place))->values();
        }

        if (! $rule->forAnyone()) {
            $wanted = $rule->memberIds();

            $events = $events->filter(
                fn (Event $e) => $e->members->pluck('id')->intersect($wanted)->isNotEmpty()
            )->values();
        }

        return $events;
    }

    /**
     * When this event actually happens, inside the window.
     *
     * Recurring events are stored as one row carrying an RRULE — the sync does
     * not expand them — so a weekly training has a single row sitting at its
     * first occurrence. For reminders that has to be expanded or the family
     * gets told about football once, in September, forever.
     *
     * @return list<CarbonImmutable>
     */
    protected function occurrences(Event $event, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = CarbonImmutable::parse($event->start_at)->utc();

        if (blank($event->rrule)) {
            return $start->betweenIncluded($from, $to) ? [$start] : [];
        }

        try {
            $iterator = new RRuleIterator((string) $event->rrule, $start->toDateTime());
        } catch (Throwable) {
            // A rule we cannot read is treated as a single event rather than
            // as a reason to send nothing at all.
            return $start->betweenIncluded($from, $to) ? [$start] : [];
        }

        $out = [];

        try {
            $iterator->fastForward($from->toDateTime());

            // A bound, not a target: an unbounded daily rule over a long
            // preview window would otherwise walk forever.
            while ($iterator->valid() && count($out) < 200) {
                $moment = CarbonImmutable::instance($iterator->current())->utc();

                if ($moment->greaterThan($to)) {
                    break;
                }

                $out[] = $moment;
                $iterator->next();
            }
        } catch (Throwable) {
            return [];
        }

        return $out;
    }

    /** Whether an event happens at a place, by the names that place answers to. */
    protected function atPlace(Event $event, ?Place $place): bool
    {
        if (! $place) {
            return false;
        }

        $haystack = mb_strtolower($event->title.' '.($event->location ?? ''));

        foreach ($place->matchTerms() as $term) {
            if ($term !== '' && str_contains($haystack, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    /** MySQL rejects LIKE … ESCAPE '\', so the wildcards are simply removed. */
    protected function escape(string $term): string
    {
        return str_replace(['%', '_'], '', $term);
    }
}
