<?php

namespace App\Services\Prompt;

use App\Models\Household;

/**
 * Who lives here, and what the places in their lives are called.
 *
 * Every prompt that reasons about this household needs the same paragraph:
 * without it a model can only quote "Holy Trinity School" back, and with it
 * the quote is one the household's own matching already understands. Shared
 * so the capture pipeline and the assistant describe the family identically —
 * two descriptions that drift are two families as far as a model is concerned.
 */
class HouseholdBrief
{
    /** @return list<string> */
    public function lines(Household $household): array
    {
        $lines = [];

        $members = $household->members;

        if ($members->isEmpty()) {
            return $lines;
        }

        $lines[] = '';
        $lines[] = 'The household:';

        foreach ($members as $member) {
            $aliases = $member->aliases->pluck('alias')->all();

            $lines[] = sprintf(
                '- %s (%s)%s',
                $member->name,
                $member->is_child ? 'child' : 'adult',
                $aliases === [] ? '' : ', also written as '.implode(', ', $aliases),
            );
        }

        $places = $household->places;

        if ($places->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Places in their lives:';

            foreach ($places as $place) {
                $names = collect([$place->name])->merge($place->aliases->pluck('alias'))->unique();
                $people = $place->members->pluck('name');

                $lines[] = sprintf(
                    '- %s (%s)%s%s',
                    $names->implode(' / '),
                    $place->type,
                    $people->isEmpty() ? '' : ' — '.$people->implode(' and '),
                    $people->isEmpty() ? '' : ($place->type === 'school' ? ' goes there' : ''),
                );
            }
        }

        return $lines;
    }

    /** The same, as one block of text. */
    public function text(Household $household): string
    {
        return trim(implode("\n", $this->lines($household)));
    }
}
