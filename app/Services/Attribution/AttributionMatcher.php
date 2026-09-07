<?php

namespace App\Services\Attribution;

use App\Models\Household;
use App\Models\Member;
use App\Models\Place;

/**
 * Works out which family members an event title is about.
 *
 * Compiled once per household and reused across events, so a whole sync costs
 * one pass over the members and places rather than one per event.
 *
 * Matching is case-insensitive and anchored to word boundaries, so "Jo" does
 * not match "Jones" — an alias only counts when it stands as its own word or
 * phrase. Multi-word aliases work the same way ("Ice and a Slice").
 */
final class AttributionMatcher
{
    public const REASON_ALIAS = 'alias';

    public const REASON_PLACE = 'place';

    public const REASON_CALENDAR = 'calendar';

    /** @param list<array{pattern: string, member_id: int, reason: string}> $rules */
    private function __construct(private readonly array $rules) {}

    public static function forHousehold(Household $household): self
    {
        $rules = [];

        foreach ($household->members()->with('aliases')->get() as $member) {
            foreach ($member->matchTerms() as $term) {
                if ($pattern = self::pattern($term)) {
                    $rules[] = ['pattern' => $pattern, 'member_id' => $member->id, 'reason' => self::REASON_ALIAS];
                }
            }
        }

        foreach ($household->places()->with(['aliases', 'automaticMembers'])->get() as $place) {
            foreach ($place->matchTerms() as $term) {
                $pattern = self::pattern($term);

                if (! $pattern) {
                    continue;
                }

                // Only members whose "include me automatically" is on. Someone
                // opted out of a shared workplace is added by name or not at all.
                foreach ($place->automaticMembers as $member) {
                    $rules[] = ['pattern' => $pattern, 'member_id' => $member->id, 'reason' => self::REASON_PLACE];
                }
            }
        }

        return new self($rules);
    }

    /** @param list<array{pattern: string, member_id: int, reason: string}> $rules */
    public static function fromRules(array $rules): self
    {
        return new self($rules);
    }

    /**
     * Member ids matched in the given text, each with why.
     *
     * @return array<int, string> member id => reason
     */
    public function match(?string ...$fields): array
    {
        $haystack = self::normalise(implode(' ', array_filter($fields, fn ($f) => $f !== null && $f !== '')));

        if ($haystack === '') {
            return [];
        }

        $matched = [];

        foreach ($this->rules as $rule) {
            if (preg_match($rule['pattern'], $haystack) !== 1) {
                continue;
            }

            // A name beats a place: being named in the title is the stronger
            // signal, and it is how someone opted out of a place gets added.
            if (! isset($matched[$rule['member_id']]) || $rule['reason'] === self::REASON_ALIAS) {
                $matched[$rule['member_id']] = $rule['reason'];
            }
        }

        return $matched;
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    /**
     * A whole-word, case-insensitive pattern for one term.
     *
     * The boundaries are lookarounds for letters and digits rather than \b,
     * because \b would let "Jo" match inside "Jones" for terms ending in
     * punctuation, and would misbehave around apostrophes and accents.
     */
    private static function pattern(string $term): ?string
    {
        $term = self::normalise($term);

        if ($term === '') {
            return null;
        }

        return '/(?<![\p{L}\p{N}])'.preg_quote($term, '/').'(?![\p{L}\p{N}])/iu';
    }

    /** Collapse whitespace so "SW  +  JW" and "SW + JW" match the same terms. */
    private static function normalise(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
