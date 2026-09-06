<?php

namespace App\Services\CalDav;

use App\Exceptions\CalDavException;
use Illuminate\Support\Collection;

/**
 * Walks the CalDAV bootstrap chain: root → principal → calendar-home → calendars.
 */
class Discovery
{
    public function __construct(protected CalDavClient $client) {}

    /**
     * The principal URL for the authenticated user.
     *
     * iCloud answers this on "/", which is also how we verify credentials —
     * a wrong app-specific password fails here with a 401.
     */
    public function principalUrl(): string
    {
        $response = $this->client->propfind('/', ['d:current-user-principal'], depth: 0);

        $principal = $response->resources
            ->map(fn (DavResource $r) => $r->property('current-user-principal'))
            ->filter()
            ->first();

        return $principal ?? throw CalDavException::discoveryFailed('principal URL');
    }

    public function calendarHomeUrl(string $principalUrl): string
    {
        $response = $this->client->propfind($principalUrl, ['c:calendar-home-set'], depth: 0);

        $home = $response->resources
            ->map(fn (DavResource $r) => $r->property('calendar-home-set'))
            ->filter()
            ->first();

        return $home ?? throw CalDavException::discoveryFailed('calendar home');
    }

    /**
     * Event calendars in the home collection.
     *
     * The home also contains contact groups, reminder lists and inbox/outbox
     * collections, all of which must be filtered out.
     *
     * @return Collection<int, array{href: string, name: string, colour: ?string, ctag: ?string, supports_sync: bool}>
     */
    public function calendars(string $calendarHomeUrl): Collection
    {
        $response = $this->client->propfind($calendarHomeUrl, [
            'd:displayname',
            'd:resourcetype',
            'd:sync-token',
            'c:supported-calendar-component-set',
            'cs:getctag',
            'ic:calendar-color',
        ], depth: 1);

        return $response->resources
            ->filter(fn (DavResource $r) => $r->isCalendar() && $r->holdsEvents())
            // The home itself can come back as a calendar on some servers.
            ->reject(fn (DavResource $r) => rtrim($r->href, '/') === rtrim($calendarHomeUrl, '/'))
            ->map(fn (DavResource $r) => [
                'href' => $r->href,
                'name' => $r->property('displayname') ?: $this->nameFromHref($r->href),
                'colour' => $this->normaliseColour($r->property('calendar-color')),
                'ctag' => $r->property('getctag'),
                // A sync-token in the PROPFIND means sync-collection is available.
                'supports_sync' => $r->property('sync-token') !== null,
            ])
            ->values();
    }

    protected function nameFromHref(string $href): string
    {
        return urldecode(basename(rtrim($href, '/'))) ?: 'Calendar';
    }

    /** Apple sends #RRGGBBAA; our colour columns hold #RRGGBB. */
    protected function normaliseColour(?string $colour): ?string
    {
        if ($colour === null) {
            return null;
        }

        $hex = ltrim(trim($colour), '#');

        return preg_match('/^[0-9a-fA-F]{6}/', $hex, $m) ? '#'.strtolower($m[0]) : null;
    }
}
