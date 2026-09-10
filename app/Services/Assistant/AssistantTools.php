<?php

namespace App\Services\Assistant;

use App\Models\Household;
use App\Services\Meals\MealPlanProposer;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The tools the assistant is given, and what running one does.
 *
 * All but one are reads. There is deliberately no tool that ticks, buys,
 * sends or deletes anything, and nothing here reaches iCloud — the assistant
 * cannot change the household because there is no verb here that would let
 * it, not because it has been asked nicely not to.
 *
 * The exception is propose_meal_plan, and it is an exception in name only: it
 * writes to a table of suggestions the planner draws in ghost text, and a
 * grown-up still has to accept each night. A model that proposes something
 * daft costs the family one tap.
 *
 * The descriptions matter as much as the schemas: they are what decides
 * whether "what's for tea?" reaches the meal plan or the search box.
 */
class AssistantTools
{
    public function __construct(
        protected HouseholdFacts $facts,
        protected MealPlanProposer $proposer,
    ) {}

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
                'meal_ideas',
                'Everything in the recipe box at once, with what it is tagged, what the grown-ups scored it out of '
                .'five, what the children thought, and when it was last cooked. Read this before proposing a week, '
                .'so a plan can point at ideas the family already has.',
                [],
                [],
            ),

            $this->tool(
                'propose_meal_plan',
                'Put a suggested week in front of the family. This does NOT save anything: the planner draws each '
                .'night in ghost text with Keep and No thanks beside it, and nothing is on anybody\'s calendar '
                .'until a grown-up accepts it. Use it when asked to plan, fill or suggest a week of dinners. '
                .'Read meal_ideas and meals first: only propose nights that are still empty. Propose things they '
                .'have not had lately as well as ones they like — a plan of the same six favourites is not a plan. '
                .'Honour anything they asked for (a veggie night, something quick on a busy evening) and say so in '
                .'each "why".',
                [
                    'week_start' => $this->dateProperty('The Monday of the week being planned, YYYY-MM-DD.'),
                    'entries' => [
                        'type' => 'array',
                        'description' => 'One per night. Only nights with nothing planned already.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'on' => $this->dateProperty('The night, YYYY-MM-DD. Must be inside that week.'),
                                'title' => ['type' => 'string', 'description' => 'What to have, as the family would write it on a planner.'],
                                'recipe_id' => ['type' => 'integer', 'description' => 'The id from meal_ideas when this is one of their saved ideas. Leave out for something new.'],
                                'why' => ['type' => 'string', 'description' => 'A few words on why this night — "quick, swimming after school". Shown to the family.'],
                            ],
                            'required' => ['on', 'title'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'note' => ['type' => 'string', 'description' => 'One line on how the week hangs together. Shown above the suggestions.'],
                ],
                ['week_start', 'entries'],
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
            'meal_ideas' => $this->facts->mealIdeas($household),
            'propose_meal_plan' => $this->proposeMealPlan($household, $input),
            'search' => $this->facts->search($household, (string) ($input['query'] ?? '')),
            default => 'There is no tool called '.$name.'.',
        };
    }

    /**
     * The one tool that writes a row — and the row is not a meal.
     *
     * Everything it stages is drawn as ghost text in the planner until a
     * grown-up taps Keep. That is what makes this safe enough to hand a
     * language model: the worst it can do is put a bad suggestion on screen,
     * and the family's answer to a bad suggestion is already there next to it.
     *
     * @param  array<string, mixed>  $input
     */
    protected function proposeMealPlan(Household $household, array $input): string
    {
        $weekStart = $this->facts->date($household, $input['week_start'] ?? null);

        $result = $this->proposer->propose(
            $household,
            $weekStart,
            array_values(array_filter((array) ($input['entries'] ?? []), 'is_array')),
            note: filled($input['note'] ?? null) ? (string) $input['note'] : null,
        );

        return $this->proposer->describe($household, $result, $weekStart);
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
