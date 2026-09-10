<?php

namespace App\Services\Notifications;

use App\Models\Event;
use App\Models\ReminderRule;
use Carbon\CarbonImmutable;

/** One reminder, about one occurrence of one event, at one moment. */
class DueReminder
{
    public function __construct(
        public readonly ReminderRule $rule,
        public readonly Event $event,
        /** When the event itself starts — the occurrence, not the series master. */
        public readonly CarbonImmutable $startsAt,
        public readonly ReminderTime $time,
        /** When to tell them. */
        public readonly CarbonImmutable $at,
    ) {}

    /**
     * What the ledger keys on, so the same reminder is never sent twice.
     *
     * The occurrence's own start is in it: a weekly training has one row in
     * the database and fifty-two reminders, and keying on the event alone
     * would send the first and then go quiet for a year.
     */
    public function subject(): string
    {
        return 'rule:'.$this->rule->id
            .':event:'.$this->event->id
            .':'.$this->startsAt->utc()->format('YmdHi')
            .':'.$this->time->toString();
    }

    /** "at 15:30 today", "tomorrow at 09:00", "on Friday at 09:00". */
    public function whenWords(string $timezone, ?CarbonImmutable $now = null): string
    {
        $local = $this->startsAt->timezone($timezone);
        $now = ($now ?? CarbonImmutable::now())->timezone($timezone);

        $day = match (true) {
            $local->isSameDay($now) => 'today',
            $local->isSameDay($now->addDay()) => 'tomorrow',
            $local->lessThan($now->addDays(7)) => 'on '.$local->format('l'),
            default => 'on '.$local->format('l j M'),
        };

        return $this->event->all_day
            ? $day.', all day'
            : $day.' at '.$local->format('H:i');
    }
}
