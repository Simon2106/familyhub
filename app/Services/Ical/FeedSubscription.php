<?php

namespace App\Services\Ical;

use App\Exceptions\IcalException;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A calendar somebody else keeps, read into this one.
 *
 * A school's fixtures list or a club's season is not a FamilyHub feature so
 * much as an absence of one: the events go in the ordinary events table on an
 * ordinary calendar, and the wall, the phone, search and the assistant never
 * learn there is a second kind. What makes it read-only is that there is no
 * writer for this provider and the calendar is created is_writable = false —
 * not a rule somebody has to remember.
 *
 * Nothing here ever writes back to the feed, and nothing here touches iCloud.
 */
class FeedSubscription
{
    /** How far either side of today is worth keeping. */
    public const MONTHS_BACK = 6;

    public const MONTHS_AHEAD = 18;

    public function __construct(protected IcalFeed $feed) {}

    /** Every subscription this household keeps. */
    public function all(Household $household): Collection
    {
        return CalendarAccount::query()
            ->where('household_id', $household->id)
            ->where('provider', CalendarAccount::PROVIDER_ICS)
            ->with('calendars.member')
            ->orderBy('label')
            ->get();
    }

    /**
     * Add one, or change one that is already there.
     *
     * The feed is read before anything is stored. A subscription that turns
     * out to be a login page should be an error on the form, not a broken row
     * somebody discovers on the wall a week later.
     */
    public function save(
        Household $household,
        string $url,
        string $name,
        string $colour = '#2563eb',
        ?Member $member = null,
        int $refreshMinutes = CalendarAccount::DEFAULT_REFRESH_MINUTES,
        ?CalendarAccount $existing = null,
    ): CalendarAccount {
        $name = trim($name) !== '' ? trim($name) : 'Subscribed calendar';
        $entries = $this->feed->fetch($url);

        $account = $existing ?? new CalendarAccount(['household_id' => $household->id]);

        $account->forceFill([
            'household_id' => $household->id,
            'provider' => CalendarAccount::PROVIDER_ICS,
            'label' => $name,
            'feed_url' => trim($url),
            'refresh_minutes' => array_key_exists($refreshMinutes, CalendarAccount::REFRESH_CHOICES)
                ? $refreshMinutes
                : CalendarAccount::DEFAULT_REFRESH_MINUTES,
            'status' => 'ok',
            'last_error' => null,
        ])->save();

        $calendar = $account->calendars()->first() ?? new Calendar;

        $calendar->forceFill([
            'calendar_account_id' => $account->id,
            'member_id' => $member?->id,
            // Stable, so re-saving a subscription updates its calendar rather
            // than orphaning every event already on it.
            'external_id' => 'ics:'.sha1($account->id.'|'.$account->feed_url),
            'name' => $name,
            'colour' => $colour,
            'is_visible' => true,
            // The one thing that makes this read-only, in the one place
            // anything ever asks.
            'is_writable' => false,
        ])->save();

        $this->store($account, $calendar, $entries);

        return $account->fresh(['calendars']);
    }

    /** Re-read one feed. Errors are recorded, never thrown at a schedule. */
    public function refresh(CalendarAccount $account): int
    {
        $calendar = $account->calendars()->first();

        if (! $calendar || blank($account->feed_url)) {
            return 0;
        }

        try {
            $entries = $this->feed->fetch($account->feed_url);
        } catch (IcalException $e) {
            // The events already read stay on the wall. A feed that is down
            // this morning is not a reason to empty somebody's calendar.
            $account->forceFill(['status' => 'error', 'last_error' => $e->getMessage()])->save();

            return 0;
        }

        return $this->store($account, $calendar, $entries);
    }

    public function forget(CalendarAccount $account): void
    {
        // The calendar and its events go with it, by the cascade. Somebody
        // unsubscribing wants the fixtures off their wall.
        $account->delete();
    }

    /**
     * Write the entries onto the calendar, and take away what has gone.
     *
     * Removals matter as much as additions: a cancelled fixture disappears
     * from the feed, and a wall still showing it is worse than one that never
     * showed it. Only inside the window, so pruning cannot delete history the
     * feed has simply stopped publishing.
     *
     * @param  Collection<int, IcalEntry>  $entries
     */
    protected function store(CalendarAccount $account, Calendar $calendar, Collection $entries): int
    {
        $now = CarbonImmutable::now();
        $from = $now->subMonths(self::MONTHS_BACK);
        $to = $now->addMonths(self::MONTHS_AHEAD);

        $seen = [];
        $written = 0;

        foreach ($entries as $entry) {
            $start = $entry->startMoment();

            if ($start->lessThan($from) || $start->greaterThan($to)) {
                continue;
            }

            $end = $entry->endMoment();

            Event::updateOrCreate(
                [
                    'calendar_id' => $calendar->id,
                    'uid_hash' => Event::uidHash($entry->uid),
                ],
                [
                    'external_id' => $entry->uid,
                    'title' => mb_substr($entry->summary, 0, 255),
                    'start_at' => $start,
                    'end_at' => $end->lessThan($start) ? $start : $end,
                    'all_day' => $entry->allDay,
                    'location' => $entry->location ? mb_substr($entry->location, 0, 255) : null,
                    'notes' => $entry->description,
                    'status' => 'confirmed',
                ],
            );

            $seen[] = Event::uidHash($entry->uid);
            $written++;
        }

        Event::query()
            ->where('calendar_id', $calendar->id)
            ->whereBetween('start_at', [$from, $to])
            ->when($seen !== [], fn ($q) => $q->whereNotIn('uid_hash', $seen))
            ->delete();

        $account->forceFill([
            'status' => 'ok',
            'last_error' => null,
            'last_synced_at' => $now,
        ])->save();

        $calendar->forceFill(['last_synced_at' => $now])->save();

        return $written;
    }
}
