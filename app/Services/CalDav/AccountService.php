<?php

namespace App\Services\CalDav;

use App\Exceptions\CalDavException;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Household;
use Throwable;

/**
 * Pairing and calendar-list refresh for an iCloud account.
 */
class AccountService
{
    public function __construct(protected CalDavManager $manager) {}

    /**
     * Verify credentials before anything is stored, and return the principal URL.
     *
     * @throws CalDavException
     */
    public function verify(string $appleId, string $appPassword): string
    {
        $client = $this->manager->clientForCredentials($appleId, $appPassword);

        return (new Discovery($client))->principalUrl();
    }

    /**
     * Create or update an iCloud account from credentials entered in /admin.
     *
     * Credentials are never read from .env: the brief requires several Apple IDs
     * to coexist, each added by the person who owns it.
     */
    public function connect(Household $household, string $label, string $appleId, string $appPassword): CalendarAccount
    {
        $principal = $this->verify($appleId, $appPassword);

        $account = CalendarAccount::firstOrNew([
            'provider' => CalendarAccount::PROVIDER_ICLOUD,
            'external_account_id' => $appleId,
        ]);

        $account->household_id = $household->id;
        $account->label = $label !== '' ? $label : $appleId;
        $account->credentials = [
            'username' => $appleId,
            'password' => $appPassword,
            'base_url' => config('familyhub.caldav.icloud_url'),
        ];
        $account->principal_url = $principal;
        $account->status = 'ok';
        $account->last_error = null;
        $account->save();

        $this->refreshCalendars($account);

        return $account;
    }

    /**
     * Re-read the calendar list, adding new calendars and retiring vanished ones.
     *
     * Member assignment, colour and visibility are chosen in our UI, so they are
     * never overwritten by a refresh.
     */
    public function refreshCalendars(CalendarAccount $account): int
    {
        $discovery = $this->manager->discovery($account);

        $home = $account->calendar_home_url
            ?: $discovery->calendarHomeUrl($account->principal_url ?: $discovery->principalUrl());

        $account->forceFill(['calendar_home_url' => $home])->save();

        $found = $discovery->calendars($home);

        foreach ($found as $spec) {
            $calendar = Calendar::firstOrNew([
                'calendar_account_id' => $account->id,
                'external_id' => $spec['href'],
            ]);

            $calendar->name = $spec['name'];
            $calendar->ctag = $spec['ctag'];
            $calendar->supports_sync_collection = $spec['supports_sync'];

            if (! $calendar->exists) {
                // Only seed presentation on first sight; after that it is ours.
                $calendar->colour = $spec['colour'] ?? '#2563eb';
                $calendar->is_visible = true;
                $calendar->is_writable = true;
            }

            $calendar->save();
        }

        // A calendar deleted in iCloud should stop appearing on the wall, but
        // its rows are kept so a re-added calendar does not lose its member.
        Calendar::where('calendar_account_id', $account->id)
            ->whereNotIn('external_id', $found->pluck('href')->all())
            ->update(['is_visible' => false, 'is_writable' => false]);

        return $found->count();
    }

    public function recordFailure(CalendarAccount $account, Throwable $e): void
    {
        $account->forceFill([
            'status' => 'error',
            'last_error' => mb_substr($e->getMessage(), 0, 500),
        ])->save();
    }

    public function recordSuccess(CalendarAccount $account): void
    {
        $account->forceFill([
            'status' => 'ok',
            'last_error' => null,
            'last_synced_at' => now(),
        ])->save();
    }
}
