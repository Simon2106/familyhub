<?php

namespace App\Services\Assistant;

use App\Models\Household;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The tools the assistant is given, and what running one does.
 *
 * Every tool is a read. There is deliberately no tool that writes, ticks,
 * plans, buys or sends anything — the assistant cannot change the household
 * because there is no verb here that would let it, not because it has been
 * asked nicely not to.
 *
 * The descriptions matter as much as the schemas: they are what decides
 * whether "what's for tea?" reaches the meal plan or the search box.
 */
class AssistantTools
{
    public function __construct(protected HouseholdFacts $facts) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            $this->tool(
                'calendar',
                'Events in the calendars between two dates: what is on, when, where, and whose calendar it is on. '
                .'Use this for anything about plans, appointments, who is doing what, or where somebody will be.',
                [
                    'from' => $this->dateProperty('First day to look at, YYYY-MM-DD.'),
                    'to' => $this->dateProperty('Last day to look at, YYYY-MM-DD. Same as "from" for a single day.'),
                    'member' => ['type' => 'string', 'description' => 'Optional: only events concerning this person.'],
                ],
                ['from', 'to'],
            ),

            $this->tool(
                'meals',
                'The meal plan between two dates — what is planned for breakfast, lunch or dinner.',
                [
                    'from' => $this->dateProperty('First day, YYYY-MM-DD.'),
                    'to' => $this->dateProperty('Last day, YYYY-MM-DD.'),
                ],
                ['from', 'to'],
            ),

            $this->tool(
                'chores',
                'Chores due between two dates, and whether each is done, waiting for approval, or still outstanding.',
                [
                    'from' => $this->dateProperty('First day, YYYY-MM-DD.'),
                    'to' => $this->dateProperty('Last day, YYYY-MM-DD.'),
                    'member' => ['type' => 'string', 'description' => 'Optional: only this child\'s chores.'],
                ],
                ['from', 'to'],
            ),

            $this->tool(
                'lists',
                'The to-do list and the shopping list: what is on them, what is due when, and who it is for.',
                [
                    'list' => [
                        'type' => 'string',
                        'enum' => ['todo', 'shopping', 'all'],
                        'description' => 'Which list to read. Defaults to both.',
                    ],
                    'include_done' => ['type' => 'boolean', 'description' => 'Include things already ticked off. Defaults to false.'],
                ],
                [],
            ),

            $this->tool(
                'bins',
                'The next bin collections: which bins go out on which day.',
                [
                    'weeks' => ['type' => 'integer', 'description' => 'How many weeks ahead to look. Defaults to 4, maximum 12.'],
                ],
                [],
            ),

            $this->tool(
                'school_dates',
                'School term dates: holidays, INSET days, bank holidays, and the last day of term or first day back.',
                [
                    'from' => $this->dateProperty('First day, YYYY-MM-DD.'),
                    'to' => $this->dateProperty('Last day, YYYY-MM-DD.'),
                ],
                ['from', 'to'],
            ),

            $this->tool(
                'points',
                'Points balances for the children, and anything done but still waiting for a grown-up to approve it.',
                [
                    'member' => ['type' => 'string', 'description' => 'Optional: just this child.'],
                ],
                [],
            ),

            $this->tool(
                'recipe',
                'One saved recipe by name, with its ingredients and method.',
                [
                    'name' => ['type' => 'string', 'description' => 'The recipe title, or part of it.'],
                ],
                ['name'],
            ),

            $this->tool(
                'search',
                'Free-text search across everything at once — events, to-dos, shopping, chores, routines, meals, '
                .'recipes, rewards, the review inbox, people and places. Use it when no other tool fits, or when '
                .'you do not know the date something is on.',
                [
                    'query' => ['type' => 'string', 'description' => 'What to look for. A period may be included, as in "dentist march".'],
                ],
                ['query'],
            ),
        ];
    }

    /**
     * Run one tool call.
     *
     * A tool that throws comes back as words rather than an exception: a
     * missing school feed should make the assistant say it does not know, not
     * turn the whole answer into an error page.
     *
     * @param  array<string, mixed>  $input
     */
    public function run(string $name, array $input, Household $household): string
    {
        try {
            return $this->dispatch($name, $input, $household);
        } catch (Throwable $e) {
            report($e);

            return 'That could not be read: '.$e->getMessage();
        }
    }

    /** @param array<string, mixed> $input */
    protected function dispatch(string $name, array $input, Household $household): string
    {
        $member = filled($input['member'] ?? null) ? (string) $input['member'] : null;

        return match ($name) {
            'calendar' => $this->facts->calendar($household, ...$this->range($input, $household), member: $member),
            'meals' => $this->facts->meals($household, ...$this->range($input, $household)),
            'chores' => $this->facts->chores($household, ...$this->range($input, $household), member: $member),
            'lists' => $this->facts->lists(
                $household,
                is_string($input['list'] ?? null) ? $input['list'] : 'all',
                (bool) ($input['include_done'] ?? false),
            ),
            'bins' => $this->facts->bins($household, (int) ($input['weeks'] ?? 4)),
            'school_dates' => $this->facts->schoolDates($household, ...$this->range($input, $household)),
            'points' => $this->facts->points($household, $member),
            'recipe' => $this->facts->recipe($household, (string) ($input['name'] ?? '')),
            'search' => $this->facts->search($household, (string) ($input['query'] ?? '')),
            default => 'There is no tool called '.$name.'.',
        };
    }

    /**
     * The from/to pair, made safe.
     *
     * @param  array<string, mixed>  $input
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    protected function range(array $input, Household $household): array
    {
        $from = $this->facts->date($household, $input['from'] ?? null);

        return [
            'from' => $from,
            'to' => $this->facts->clamp($from, $this->facts->date($household, $input['to'] ?? null, $from)),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected function tool(string $name, string $description, array $properties, array $required): array
    {
        return [
            'name' => $name,
            'description' => $description,
            // camelCase: the PHP SDK maps it to input_schema on the wire.
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function dateProperty(string $description): array
    {
        return ['type' => 'string', 'description' => $description];
    }
}
