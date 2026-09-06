<?php

namespace App\Services\CalDav;

use SimpleXMLElement;

/**
 * One <d:response> from a multistatus body: an href plus whatever properties
 * the server chose to return for it.
 */
class DavResource
{
    /** @param array<string, string> $properties keyed by local element name */
    public function __construct(
        public readonly string $href,
        public readonly array $properties = [],
        public readonly array $resourceTypes = [],
        public readonly array $supportedComponents = [],
        public readonly int $status = 200,
    ) {}

    public static function fromResponseNode(SimpleXMLElement $node, string $baseUrl = ''): ?self
    {
        MultiStatus::registerNamespaces($node);

        $hrefNodes = $node->xpath('d:href') ?: [];

        if (! $hrefNodes) {
            return null;
        }

        $href = trim((string) $hrefNodes[0]);

        // A 404 propstat inside sync-collection is how deletions are reported.
        $statusNodes = $node->xpath('d:status') ?: [];
        $status = $statusNodes ? self::statusCode((string) $statusNodes[0]) : 200;

        $properties = [];

        foreach ($node->xpath('d:propstat') ?: [] as $propstat) {
            MultiStatus::registerNamespaces($propstat);

            $propstatStatus = $propstat->xpath('d:status') ?: [];

            // Skip properties the server said it could not supply.
            if ($propstatStatus && self::statusCode((string) $propstatStatus[0]) >= 400) {
                continue;
            }

            foreach ($propstat->xpath('d:prop/*') ?: [] as $prop) {
                $properties[$prop->getName()] = self::propertyValue($prop);
            }
        }

        return new self(
            href: $href,
            properties: $properties,
            resourceTypes: self::childNames($node, 'd:propstat/d:prop/d:resourcetype/*'),
            supportedComponents: self::componentNames($node),
            status: $status,
        );
    }

    /**
     * Some properties hold their value in a nested <d:href> rather than as
     * text — current-user-principal and calendar-home-set both do — and casting
     * such an element to string yields "".
     */
    protected static function propertyValue(SimpleXMLElement $prop): string
    {
        $href = $prop->children(MultiStatus::DAV)->href ?? null;

        if ($href !== null && count($href) > 0) {
            return trim((string) $href[0]);
        }

        return trim((string) $prop);
    }

    public function property(string $name): ?string
    {
        $value = $this->properties[$name] ?? null;

        return $value === '' ? null : $value;
    }

    public function isCalendar(): bool
    {
        return in_array('calendar', $this->resourceTypes, strict: true);
    }

    /** iCloud exposes contacts and other collections in the same home; only take event calendars. */
    public function holdsEvents(): bool
    {
        // An empty supported-component-set means "everything", per RFC 4791.
        return $this->supportedComponents === [] || in_array('VEVENT', $this->supportedComponents, strict: true);
    }

    public function isDeleted(): bool
    {
        return $this->status === 404;
    }

    public function calendarData(): ?string
    {
        return $this->property('calendar-data');
    }

    public function etag(): ?string
    {
        $etag = $this->property('getetag');

        return $etag === null ? null : trim($etag);
    }

    /** @return list<string> */
    protected static function childNames(SimpleXMLElement $node, string $xpath): array
    {
        MultiStatus::registerNamespaces($node);

        return array_values(array_map(
            fn (SimpleXMLElement $child) => $child->getName(),
            $node->xpath($xpath) ?: [],
        ));
    }

    /** @return list<string> */
    protected static function componentNames(SimpleXMLElement $node): array
    {
        MultiStatus::registerNamespaces($node);

        $names = [];

        foreach ($node->xpath('d:propstat/d:prop/c:supported-calendar-component-set/c:comp') ?: [] as $comp) {
            $name = (string) ($comp->attributes()->name ?? '');

            if ($name !== '') {
                $names[] = strtoupper($name);
            }
        }

        return $names;
    }

    protected static function statusCode(string $statusLine): int
    {
        // "HTTP/1.1 404 Not Found"
        return (int) (preg_match('#\s(\d{3})\s#', $statusLine.' ', $m) ? $m[1] : 200);
    }
}
