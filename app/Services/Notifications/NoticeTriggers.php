<?php

namespace App\Services\Notifications;

use App\Models\CaptureItem;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Household;
use App\Models\Redemption;
use App\Models\User;
use App\Services\Chores\ChoreBoard;
use App\Services\Chores\ChoreSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What is worth telling the grown-ups about, right now.
 *
 * Each trigger answers the same question — is there something the household
 * would want to know that it has not already been told — and every notice
 * carries a subject that identifies the *thing*, not the moment. That is what
 * keeps a standing pile of review items from being announced once a minute
 * for a fortnight.
 */
class NoticeTriggers
{
    /** How long before a nudge about a standing pile is worth repeating. */
    public const REMIND_AGAIN_DAYS = 1;

    public function __construct(protected NotificationSettings $settings) {}

    /**
     * Everything this person should be told at this moment.
     *
     * @return Collection<int, Notice>
     */
    public function forUser(User $user, Household $household, CarbonImmutable $now): Collection
    {
        return collect([
            ...$this->eventReminders($user, $household, $now),
            ...$this->reviewWaiting($household, $now),
            ...$this->approvalWaiting($household, $now),
            ...$this->choresDue($household, $now),
        ]);
    }

    /**
     * Events starting within this person's chosen warning.
     *
     * The window is the minute the reminder is due rather than "anything in
     * the next hour", so moving the lead time from an hour to fifteen minutes
     * does not immediately fire for everything this afternoon.
     *
     * @return list<Notice>
     */
    protected function eventReminders(User $user, Household $household, CarbonImmutable $now): array
    {
        $lead = $this->settings->leadMinutes($user);
        $due = $now->addMinutes($lead);

        $events = Event::query()
            ->notCancelled()
            ->whereHas('calendar', fn ($q) => $q
                ->where('is_visible', true)
                ->whereHas('account', fn ($a) => $a->where('household_id', $household->id)))
            ->where('all_day', false)
            ->whereBetween('start_at', [$due->startOfMinute(), $due->addMinutes(4)->endOfMinute()])
            ->with('members')
            ->get();

        return $events->map(fn (Event $event) => new Notice(
            trigger: 'event_reminder',
            subject: 'event:'.$event->id,
            title: $event->title,
            body: $this->when($event, $household, $lead),
            url: route('app', ['event' => $event->id]),
        ))->all();
    }

    protected function when(Event $event, Household $household, int $lead): string
    {
        $at = $event->start_at->timezone($household->displayTimezone())->format('H:i');
        $who = $event->members->pluck('name')->join(', ');

        return match (true) {
            $lead >= 1440 => 'Tomorrow at '.$at.($who ? ' · '.$who : ''),
            $lead >= 60 => 'In an hour, at '.$at.($who ? ' · '.$who : ''),
            default => 'At '.$at.($who ? ' · '.$who : ''),
        };
    }

    /**
     * Things sitting in the review inbox.
     *
     * Subject is the day, not the count: a pile that grows from three to four
     * is the same pile, and being told again because of it is how people learn
     * to ignore a notification.
     *
     * @return list<Notice>
     */
    protected function reviewWaiting(Household $household, CarbonImmutable $now): array
    {
        if (! $this->nudgeTime($now)) {
            return [];
        }

        $waiting = CaptureItem::query()
            ->whereHas('capture', fn ($q) => $q->where('household_id', $household->id))
            ->pending()
            ->count();

        if ($waiting === 0) {
            return [];
        }

        return [new Notice(
            trigger: 'review_waiting',
            subject: 'review:'.$now->toDateString(),
            title: $waiting.' '.($waiting === 1 ? 'thing' : 'things').' waiting to be checked',
            body: 'Captured from email, a photo or a link.',
            url: route('review'),
        )];
    }

    /** @return list<Notice> */
    protected function approvalWaiting(Household $household, CarbonImmutable $now): array
    {
        if (! $this->nudgeTime($now)) {
            return [];
        }

        $chores = app(ChoreBoard::class)->awaitingApproval($household)->count();
        $rewards = Redemption::where('household_id', $household->id)->pending()->count();
        $total = $chores + $rewards;

        if ($total === 0) {
            return [];
        }

        return [new Notice(
            trigger: 'approval_waiting',
            subject: 'approvals:'.$now->toDateString(),
            title: $total.' waiting for a grown-up',
            body: trim(collect([
                $chores ? $chores.' '.($chores === 1 ? 'chore' : 'chores') : null,
                $rewards ? $rewards.' '.($rewards === 1 ? 'reward' : 'rewards') : null,
            ])->filter()->join(' and ')),
            url: route('kids'),
        )];
    }

    /**
     * A child's chore still not done, late in the day.
     *
     * Sent to the adults rather than the child, because the child is not the
     * one with the phone — and because a nudge that arrives at teatime is one
     * somebody can still act on.
     *
     * @return list<Notice>
     */
    protected function choresDue(Household $household, CarbonImmutable $now): array
    {
        if ((int) $now->format('G') !== 17 || (int) $now->format('i') > 4) {
            return [];
        }

        $outstanding = app(ChoreBoard::class)
            ->forDay($household, $now->startOfDay())
            ->filter(fn (ChoreSlot $slot) => ! $slot->isDone() && $slot->chore->member_id !== null);

        if ($outstanding->isEmpty()) {
            return [];
        }

        $names = $outstanding->map(fn (ChoreSlot $slot) => $slot->chore->member?->name)
            ->filter()->unique()->values();

        return [new Notice(
            trigger: 'chore_due',
            subject: 'chores:'.$now->toDateString(),
            title: $outstanding->count().' '.($outstanding->count() === 1 ? 'chore' : 'chores').' still to do',
            body: $names->join(' and '),
            url: route('kids'),
        )];
    }

    /**
     * The one moment in the day a standing pile is worth mentioning.
     *
     * Early evening: after school, before the wall dims, and at a time when
     * somebody might actually deal with it.
     */
    protected function nudgeTime(CarbonImmutable $now): bool
    {
        return (int) $now->format('G') === 18 && (int) $now->format('i') <= 4;
    }

    /**
     * Something added to a list, told at the moment it happens.
     *
     * Called from the list rather than polled: "milk went on the shopping
     * list" is only useful while somebody is still near a shop.
     */
    public function listAdded(ChecklistItem $item): Notice
    {
        return new Notice(
            trigger: 'list_added',
            subject: 'list-item:'.$item->id,
            title: $item->title.' added to '.($item->checklist->name ?? 'a list'),
            body: $item->member?->name ? 'for '.$item->member->name : null,
            url: route('shopping'),
        );
    }
}
