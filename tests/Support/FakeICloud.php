<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Canned iCloud CalDAV responses, shaped the way Apple actually sends them —
 * unusual namespace prefixes, quoted ETags, exclusive all-day DTENDs.
 */
class FakeICloud
{
    public const PRINCIPAL = '/12345678/principal/';

    public const HOME = '/12345678/calendars/';

    public const CALENDAR = '/12345678/calendars/home/';

    /**
     * What the next calendar-list PROPFIND returns.
     *
     * Http::fake() merges stubs rather than replacing them, and the first
     * matching stub wins — so a test cannot change iCloud's answer by calling
     * Http::fake() a second time. Swapping this instead works because the stub
     * closure reads it at request time.
     */
    public static ?string $calendarListOverride = null;

    /** @var array<string, string> REPORT responses keyed by a marker in the request body. */
    public static array $reports = [];

    /** A closure taking full control of the conversation, for error-path tests. */
    public static $handler = null;

    public static function reset(): void
    {
        self::$calendarListOverride = null;
        self::$reports = [];
        self::$handler = null;
    }

    public static function principal(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <multistatus xmlns="DAV:">
          <response>
            <href>/</href>
            <propstat>
              <prop><current-user-principal><href>/12345678/principal/</href></current-user-principal></prop>
              <status>HTTP/1.1 200 OK</status>
            </propstat>
          </response>
        </multistatus>
        XML;
    }

    public static function calendarHome(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <multistatus xmlns="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
          <response>
            <href>/12345678/principal/</href>
            <propstat>
              <prop><C:calendar-home-set><href>/12345678/calendars/</href></C:calendar-home-set></prop>
              <status>HTTP/1.1 200 OK</status>
            </propstat>
          </response>
        </multistatus>
        XML;
    }

    /** A home containing one event calendar, one reminders list, and the home itself. */
    public static function calendarList(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <A:multistatus xmlns:A="DAV:" xmlns:B="urn:ietf:params:xml:ns:caldav"
                       xmlns:C="http://calendarserver.org/ns/" xmlns:D="http://apple.com/ns/ical/">
          <A:response>
            <A:href>/12345678/calendars/</A:href>
            <A:propstat><A:prop><A:resourcetype><A:collection/></A:resourcetype></A:prop>
            <A:status>HTTP/1.1 200 OK</A:status></A:propstat>
          </A:response>
          <A:response>
            <A:href>/12345678/calendars/home/</A:href>
            <A:propstat><A:prop>
              <A:displayname>Family</A:displayname>
              <A:resourcetype><A:collection/><B:calendar/></A:resourcetype>
              <A:sync-token>token-0</A:sync-token>
              <B:supported-calendar-component-set><B:comp name="VEVENT"/></B:supported-calendar-component-set>
              <C:getctag>ctag-1</C:getctag>
              <D:calendar-color>#FF2968FF</D:calendar-color>
            </A:prop><A:status>HTTP/1.1 200 OK</A:status></A:propstat>
          </A:response>
          <A:response>
            <A:href>/12345678/calendars/reminders/</A:href>
            <A:propstat><A:prop>
              <A:displayname>Reminders</A:displayname>
              <A:resourcetype><A:collection/><B:calendar/></A:resourcetype>
              <B:supported-calendar-component-set><B:comp name="VTODO"/></B:supported-calendar-component-set>
            </A:prop><A:status>HTTP/1.1 200 OK</A:status></A:propstat>
          </A:response>
        </A:multistatus>
        XML;
    }

    /** @param array<string, string> $resources href => ics */
    public static function multiStatus(array $resources, ?string $syncToken = null, array $deleted = []): string
    {
        $body = '';

        foreach ($resources as $href => $ics) {
            $body .= sprintf(
                '<d:response><d:href>%s</d:href><d:propstat><d:prop>'
                .'<d:getetag>"%s"</d:getetag><c:calendar-data>%s</c:calendar-data>'
                .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>',
                $href,
                'etag-'.substr(md5($ics), 0, 8),
                htmlspecialchars($ics, ENT_XML1),
            );
        }

        foreach ($deleted as $href) {
            $body .= sprintf(
                '<d:response><d:href>%s</d:href><d:status>HTTP/1.1 404 Not Found</d:status></d:response>',
                $href,
            );
        }

        $token = $syncToken ? "<d:sync-token>{$syncToken}</d:sync-token>" : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            .$body.$token.'</d:multistatus>';
    }

    public static function event(string $uid, string $summary, string $start, string $end, string $extra = ''): string
    {
        return implode("\r\n", array_filter([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Apple Inc.//iOS 18.0//EN',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTART;TZID=Europe/London:{$start}",
            "DTEND;TZID=Europe/London:{$end}",
            "SUMMARY:{$summary}",
            $extra,
            'END:VEVENT',
            'END:VCALENDAR',
        ]));
    }

    public static function allDayEvent(string $uid, string $summary, string $startDate, string $endDateExclusive): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "DTSTART;VALUE=DATE:{$startDate}",
            "DTEND;VALUE=DATE:{$endDateExclusive}",
            "SUMMARY:{$summary}",
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }

    /** A recurring master plus one modified occurrence, in a single resource. */
    public static function recurringWithException(string $uid): string
    {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            'DTSTART;TZID=Europe/London:20260707T163000',
            'DTEND;TZID=Europe/London:20260707T171500',
            'RRULE:FREQ=WEEKLY;BYDAY=TU',
            'SUMMARY:Swimming lesson',
            'END:VEVENT',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            'RECURRENCE-ID;TZID=Europe/London:20260714T163000',
            'DTSTART;TZID=Europe/London:20260714T173000',
            'DTEND;TZID=Europe/London:20260714T181500',
            'SUMMARY:Swimming lesson (later)',
            'END:VEVENT',
            'END:VCALENDAR',
        ]);
    }

    /**
     * Route the whole CalDAV conversation by request method and body.
     *
     * All behaviour is held in statics and read at request time, because
     * Http::fake() merges stubs rather than replacing them — a second call
     * cannot change what an already-registered stub returns.
     *
     * @param  array<string, string>  $reports  keyed by a marker in the request body
     */
    public static function fake(array $reports = [], ?callable $handler = null): void
    {
        self::$reports = $reports;
        self::$handler = $handler;

        Http::fake([
            'caldav.icloud.com/*' => function ($request) {
                if (self::$handler !== null) {
                    return (self::$handler)($request);
                }

                return self::respond($request);
            },
        ]);
    }

    public static function respond($request)
    {
        $method = $request->method();
        $body = (string) $request->body();

        if ($method === 'PROPFIND') {
            return match (true) {
                str_contains($body, 'current-user-principal') => Http::response(self::principal(), 207),
                str_contains($body, 'calendar-home-set') => Http::response(self::calendarHome(), 207),
                default => Http::response(self::$calendarListOverride ?? self::calendarList(), 207),
            };
        }

        if ($method === 'REPORT') {
            foreach (self::$reports as $marker => $response) {
                if (str_contains($body, $marker)) {
                    return Http::response($response, 207);
                }
            }

            return Http::response(self::multiStatus([]), 207);
        }

        return match ($method) {
            'PUT' => Http::response('', 201, ['ETag' => '"etag-written"']),
            'DELETE' => Http::response('', 204),
            default => Http::response('', 200),
        };
    }
}
