<?php

namespace App\Services\Capture;

use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use Illuminate\Support\Collection;

/**
 * Who a forwarded email is probably about, from where it came from.
 *
 * A message from the school's domain is about the children at that school,
 * whatever the subject line says — and school letters are famously bad at
 * saying whose child they concern. Telling the model that outright is worth
 * more than any amount of prompting about reading carefully.
 */
class SenderHint
{
    /** Only the registrable part, so a subdomain still matches its school. */
    public function domainOf(?string $sender): ?string
    {
        if (! $sender || ! preg_match('/@([^\s>]+)/', $sender, $m)) {
            // A bare domain with no address around it is fine too.
            return $sender && preg_match('/^[\w.-]+\.\w{2,}$/', trim($sender))
                ? strtolower(trim($sender))
                : null;
        }

        return strtolower(rtrim($m[1], '>')) ?: null;
    }

    /**
     * The people and places a domain belongs to.
     *
     * @return array{domain: ?string, members: list<string>, places: list<string>}
     */
    public function forSender(?string $sender, ?Household $household = null): array
    {
        $domain = $this->domainOf($sender);

        if ($domain === null) {
            return ['domain' => null, 'members' => [], 'places' => []];
        }

        $household ??= Household::current();

        // The loaded relations, not fresh queries: ProcessCaptureJob eager
        // loads these once for the whole capture, and a hint that quietly
        // re-read them would cost a query per attachment.
        return [
            'domain' => $domain,
            'members' => $this->matching($household->members, $domain),
            'places' => $this->matching($household->places, $domain),
        ];
    }

    /** One sentence for the prompt, or nothing when there is nothing to say. */
    public function sentence(?string $sender, ?Household $household = null): ?string
    {
        $hint = $this->forSender($sender, $household);

        if ($hint['domain'] === null) {
            return null;
        }

        $line = 'Sent from '.$hint['domain'];

        if ($hint['places'] !== []) {
            $line .= ', which is '.$this->list($hint['places']);
        }

        if ($hint['members'] !== []) {
            $line .= '. That domain belongs to '.$this->list($hint['members'])
                .', so this very probably concerns '
                .(count($hint['members']) === 1 ? 'them' : 'one or more of them');
        }

        return $line.'.';
    }

    /**
     * @param  Collection<int, Member|Place>  $candidates
     * @return list<string>
     */
    protected function matching(Collection $candidates, string $domain): array
    {
        return $candidates
            ->filter(fn ($candidate) => $candidate->aliases
                ->where('kind', 'domain')
                ->contains(fn ($alias) => $this->covers(strtolower(trim($alias->alias)), $domain)))
            ->map(fn ($candidate) => $candidate->name)
            ->values()
            ->all();
    }

    /** A configured domain also covers its subdomains: mail.school.sch.uk is the school. */
    protected function covers(string $configured, string $domain): bool
    {
        if ($configured === '') {
            return false;
        }

        return $domain === $configured || str_ends_with($domain, '.'.$configured);
    }

    /** @param list<string> $items */
    protected function list(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.end($items);
    }
}
