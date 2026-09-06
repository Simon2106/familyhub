<?php

namespace App\Services\CalDav;

use Illuminate\Support\Collection;
use SimpleXMLElement;

/**
 * A parsed WebDAV 207 Multi-Status body.
 *
 * WebDAV XML is namespaced and servers disagree about prefixes, so everything
 * here works through registered namespace URIs rather than literal prefixes.
 */
class MultiStatus
{
    public const DAV = 'DAV:';

    public const CALDAV = 'urn:ietf:params:xml:ns:caldav';

    public const CALSERVER = 'http://calendarserver.org/ns/';

    public const APPLE = 'http://apple.com/ns/ical/';

    /** @param Collection<int, DavResource> $resources */
    public function __construct(
        public readonly Collection $resources,
        public readonly ?string $syncToken = null,
    ) {}

    public static function parse(string $xml, string $baseUrl = ''): self
    {
        $previous = libxml_use_internal_errors(true);

        $doc = simplexml_load_string($xml);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            return new self(collect());
        }

        self::registerNamespaces($doc);

        $resources = collect($doc->xpath('//d:response') ?: [])
            ->map(fn (SimpleXMLElement $node) => DavResource::fromResponseNode($node, $baseUrl))
            ->filter()
            ->values();

        // sync-collection replies carry the token to present on the next run.
        $tokenNodes = $doc->xpath('/d:multistatus/d:sync-token') ?: [];
        $syncToken = $tokenNodes ? trim((string) $tokenNodes[0]) : null;

        return new self($resources, $syncToken !== '' ? $syncToken : null);
    }

    public static function registerNamespaces(SimpleXMLElement $node): void
    {
        $node->registerXPathNamespace('d', self::DAV);
        $node->registerXPathNamespace('c', self::CALDAV);
        $node->registerXPathNamespace('cs', self::CALSERVER);
        $node->registerXPathNamespace('ic', self::APPLE);
    }

    public function first(): ?DavResource
    {
        return $this->resources->first();
    }
}
