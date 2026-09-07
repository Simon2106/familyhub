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
 *
 * Names win outright. If the text names anybody, the result is exactly those
 * people and places are not consulted at all — naming someone is an explicit
 * statement about who the event is for, and a place is only a default for when
 * nobody said. So "JW Ice WFH" is Jenna alone even though Ice would otherwise
 * pull in Simon, while "Ice offsite" falls through to Simon.
 */
final class AttributionMatcher
{
    public const REASON_ALIAS = 'alias';

    public const REASON_PLACE = 'place';

    public const REASON_CALENDAR = 'calendar';

    /**
     * @param  list<array{pattern: string, member_id: int}>  $aliasRules
     * @param  list<array{pattern: string, member_id: int}>  $placeRules
     */
    private function __construct(
        private readonly array $aliasRules,
        private readonly array $placeRules,
    ) {}

    public static function forHousehold(Household $household): self
    {
        $aliasRules = [];
        $placeRules = [];

        foreach ($household->members()->with('aliases')->get() as $member) {
            foreach ($member->matchTerms() as $term) {
                if ($pattern = self::pattern($term)) {
                    $aliasRules[] = ['pattern' => $pattern, 'member_id' => $member->id];
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
                    $placeRules[] = ['pattern' => $pattern, 'member_id' => $member->id];
                }
            }
        }

        return new self($aliasRules, $placeRules);
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

        // Names are decisive. Only when the text names nobody does a place get
        // to speak for its members.
        $byName = $this->evaluate($this->aliasRules, $haystack, self::REASON_ALIAS);

        return $byName !== []
            ? $byName
            : $this->evaluate($this->placeRules, $haystack, self::REASON_PLACE);
    }

    /**
     * @param  list<array{pattern: string, member_id: int}>  $rules
     * @return array<int, string>
     */
    private function evaluate(array $rules, string $haystack, string $reason): array
    {
        $matched = [];

        foreach ($rules as $rule) {
            if (preg_match($rule['pattern'], $haystack) === 1) {
                $matched[$rule['member_id']] = $reason;
            }
        }

        return $matched;
    }

    public function isEmpty(): bool
    {
        return $this->aliasRules === [] && $this->placeRules === [];
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
