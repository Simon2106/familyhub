<?php

namespace App\Services\CalDav;

use App\Exceptions\CalDavException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin CalDAV transport: authentication, the WebDAV verbs, and nothing else.
 *
 * Kept deliberately free of iCloud specifics and of any knowledge of our models
 * so it can be faked wholesale in tests with Http::fake().
 */
class CalDavClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $username,
        protected string $password,
    ) {}

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Resolve a CalDAV href against the server origin.
     *
     * Hrefs come back from PROPFIND as absolute paths ("/12345678/calendars/"),
     * occasionally as full URLs. Both have to end up as something the HTTP
     * client can actually request.
     */
    public function resolve(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($url, '/');
    }

    /**
     * PROPFIND — read properties of a resource or its children.
     *
     * @param  list<string>  $properties  namespaced property names, e.g. "d:displayname"
     */
    public function propfind(string $url, array $properties, int $depth = 0): MultiStatus
    {
        $body = $this->xmlDocument(
            '<d:propfind '.$this->namespaces().'><d:prop>'
            .implode('', array_map(fn (string $p) => "<{$p}/>", $properties))
            .'</d:prop></d:propfind>'
        );

        return $this->multiStatus('PROPFIND', $url, $body, ['Depth' => (string) $depth]);
    }

    /**
     * REPORT — the sync-collection and calendar-query workhorse.
     */
    public function report(string $url, string $body, int $depth = 1): MultiStatus
    {
        return $this->multiStatus('REPORT', $url, $this->xmlDocument($body), ['Depth' => (string) $depth]);
    }

    /** Create or replace a calendar resource. Returns the new ETag when the server sends one. */
    public function put(string $url, string $ics, ?string $ifMatch = null, bool $ifNoneMatch = false): ?string
    {
        $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];

        // If-Match guards an update against a concurrent change; If-None-Match: *
        // guards a create against overwriting a resource that already exists.
        if ($ifMatch !== null) {
            $headers['If-Match'] = $ifMatch;
        } elseif ($ifNoneMatch) {
            $headers['If-None-Match'] = '*';
        }

        $response = $this->request()
            ->withHeaders($headers)
            ->withBody($ics, 'text/calendar')
            ->send('PUT', $this->resolve($url));

        if ($response->status() === 412) {
            throw CalDavException::conflict();
        }

        $this->guard($response, 'PUT', $url);

        return $this->normaliseEtag($response->header('ETag'));
    }

    public function delete(string $url, ?string $ifMatch = null): void
    {
        $response = $this->request()
            ->withHeaders($ifMatch !== null ? ['If-Match' => $ifMatch] : [])
            ->send('DELETE', $this->resolve($url));

        // Already gone is the outcome we wanted.
        if ($response->status() === 404) {
            return;
        }

        if ($response->status() === 412) {
            throw CalDavException::conflict();
        }

        $this->guard($response, 'DELETE', $url);
    }

    protected function multiStatus(string $method, string $url, string $body, array $headers): MultiStatus
    {
        $response = $this->request()
            ->withHeaders($headers + ['Content-Type' => 'application/xml; charset=utf-8'])
            ->withBody($body, 'application/xml')
            ->send($method, $this->resolve($url));

        $this->guard($response, $method, $url);

        return MultiStatus::parse($response->body(), $this->baseUrl);
    }

    protected function request(): PendingRequest
    {
        return Http::withBasicAuth($this->username, $this->password)
            ->withHeaders(['User-Agent' => config('familyhub.caldav.user_agent')])
            ->timeout(config('familyhub.caldav.timeout'))
            // iCloud redirects the well-known bootstrap path more than once.
            ->withOptions(['allow_redirects' => ['max' => 5, 'strict' => true]]);
    }

    protected function guard(Response $response, string $method, string $url): void
    {
        if ($response->status() === 401 || $response->status() === 403) {
            throw CalDavException::authenticationFailed();
        }

        if ($response->failed()) {
            throw CalDavException::unexpectedStatus($method, $url, $response->status(), $response->body());
        }
    }

    /** ETags arrive quoted, sometimes weak. Compare and store them consistently. */
    protected function normaliseEtag(?string $etag): ?string
    {
        if ($etag === null || $etag === '') {
            return null;
        }

        return trim($etag);
    }

    protected function xmlDocument(string $inner): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>'.$inner;
    }

    public function namespaces(): string
    {
        return 'xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/" xmlns:ic="http://apple.com/ns/ical/"';
    }
}
