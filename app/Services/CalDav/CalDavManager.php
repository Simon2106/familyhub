<?php

namespace App\Services\CalDav;

use App\Models\CalendarAccount;
use App\Services\Attribution\EventAttributor;
use App\Services\Calendar\OccurrenceStore;

/**
 * Builds CalDAV services for a stored account, decrypting its credentials.
 *
 * Everything that needs to talk to iCloud goes through here, so there is one
 * place that knows how an account's secrets are shaped.
 */
class CalDavManager
{
    public function clientFor(CalendarAccount $account): CalDavClient
    {
        $credentials = $account->credentials ?? [];

        return new CalDavClient(
            baseUrl: $credentials['base_url'] ?? config('familyhub.caldav.icloud_url'),
            username: $credentials['username'] ?? (string) $account->external_account_id,
            password: $credentials['password'] ?? '',
        );
    }

    /** A client for credentials not yet saved, used to test them before storing. */
    public function clientForCredentials(string $username, string $password, ?string $baseUrl = null): CalDavClient
    {
        return new CalDavClient(
            baseUrl: $baseUrl ?? config('familyhub.caldav.icloud_url'),
            username: $username,
            password: $password,
        );
    }

    public function discovery(CalendarAccount $account): Discovery
    {
        return new Discovery($this->clientFor($account));
    }

    public function sync(CalendarAccount $account): CalendarSync
    {
        return new CalendarSync(
            $this->clientFor($account),
            new EventMapper,
            app(EventAttributor::class),
            app(OccurrenceStore::class),
        );
    }

    public function writer(CalendarAccount $account): EventWriter
    {
        return new EventWriter($this->clientFor($account), new EventMapper, app(EventAttributor::class));
    }
}
