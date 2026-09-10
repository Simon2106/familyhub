<?php

use App\Models\Household;
use App\Services\Assistant\AssistantAnswer;
use App\Services\Assistant\Contracts\Assistant;
use Livewire\Component;

/**
 * Ask about the household.
 *
 * The conversation lives here and nowhere else: it is a component property,
 * so it survives follow-up questions and disappears when the page is left.
 * Nothing is written to the database, and nothing about it is remembered
 * tomorrow — a family's questions are not a record anybody asked for.
 */
new class extends Component
{
    /** How many turns of context follow-ups get. Six is three exchanges. */
    public const REMEMBERED = 6;

    public string $question = '';

    /** @var list<array{role: string, content: string, used: list<string>}> */
    public array $exchanges = [];

    public ?string $problem = null;

    /** What each tool is called when the answer says where it looked. */
    public const SOURCES = [
        'calendar' => 'the calendar',
        'meals' => 'the meal plan',
        'chores' => 'the chore board',
        'lists' => 'the lists',
        'bins' => 'the bin schedule',
        'school_dates' => 'term dates',
        'points' => 'the points ledger',
        'recipe' => 'the recipe box',
        'meal_ideas' => 'the recipe box',
        'propose_meal_plan' => 'the meal planner',
        'search' => 'a search',
    ];

    public function ask(): void
    {
        $question = trim($this->question);
        $this->problem = null;

        if ($question === '') {
            return;
        }

        // Asked before the answer arrives, so the transcript reads in order
        // even when the answer turns out to be an error.
        $history = $this->exchanges;
        $this->exchanges[] = ['role' => 'user', 'content' => $question, 'used' => []];
        $this->question = '';

        try {
            $answer = app(Assistant::class)->ask(
                $question,
                Household::current(),
                array_slice($history, -self::REMEMBERED),
            );
        } catch (\Throwable $e) {
            report($e);

            // The failed question stays on screen: it is what they typed, and
            // asking them to type it again is the rudest possible answer.
            $this->problem = $e->getMessage();

            return;
        }

        $this->exchanges[] = [
            'role' => 'assistant',
            'content' => $answer->text,
            'used' => array_values(array_unique($answer->used)),
        ];

        // A week suggested here is drawn over there. The planner may be on the
        // same screen — the wall's Meals tab — so tell it to look again.
        if (in_array('propose_meal_plan', $answer->used, true)) {
            $this->dispatch('meal-plan-proposed');
        }

        // Older than this and nobody is following up on it any more.
        $this->exchanges = array_slice($this->exchanges, -(self::REMEMBERED * 2));
    }

    /** Wipe the conversation. Nothing was stored, so there is nothing to delete. */
    public function startAgain(): void
    {
        $this->exchanges = [];
        $this->problem = null;
        $this->question = '';
    }

    /** @param list<string> $used */
    public function looked(array $used): string
    {
        $names = array_values(array_unique(array_map(
            fn (string $tool) => self::SOURCES[$tool] ?? $tool,
            $used,
        )));

        return match (count($names)) {
            0 => '',
            1 => 'Looked at '.$names[0],
            2 => 'Looked at '.$names[0].' and '.$names[1],
            default => 'Looked at '.implode(', ', array_slice($names, 0, -1)).' and '.end($names),
        };
    }
}; ?>

<div class="rounded-2xl bg-white p-3 dark:bg-slate-900">
    <form wire:submit="ask" class="flex items-center gap-2">
        <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-900/30 dark:text-sky-400" aria-hidden="true">
            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24">
                <path d="M12 3a9 9 0 1 0 4.5 16.8L21 21l-1.2-4.5A9 9 0 0 0 12 3z" />
                <path d="M9.5 9.5a2.5 2.5 0 1 1 3.2 2.4c-.5.2-.7.6-.7 1.1v.5" />
                <path d="M12 16.5h.01" />
            </svg>
        </span>

        <label class="min-w-0 flex-1">
            <span class="sr-only">Ask about the household</span>
            <input
                wire:model="question"
                type="text"
                enterkeyhint="send"
                placeholder="Ask — “what’s on Saturday?”"
                class="touch-target w-full rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
            >
        </label>

        <button type="submit"
                class="grid touch-target shrink-0 place-items-center rounded-xl bg-sky-600 px-4 font-semibold text-white disabled:opacity-40"
                wire:loading.attr="disabled" wire:target="ask">
            <span wire:loading.remove wire:target="ask">Ask</span>
            <span wire:loading wire:target="ask" class="sr-only">Thinking</span>
            <svg wire:loading wire:target="ask" class="size-5 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" />
                <path class="opacity-75" fill="currentColor" d="M21 12a9 9 0 0 0-9-9v3a6 6 0 0 1 6 6z" />
            </svg>
        </button>
    </form>

    {{-- Said out loud, because "ask the house a question" reasonably sounds
         like something that might also change it. --}}
    <p class="mt-2 px-1 text-xs text-slate-400">
        Read-only. It looks things up — it never adds, changes or deletes anything.
    </p>

    @if ($problem)
        <p class="mt-2 rounded-xl bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-950/50 dark:text-rose-300">
            {{ $problem }}
        </p>
    @endif

    @if ($exchanges !== [])
        {{-- Newest first: the answer to what was just asked should not be the
             thing furthest from the box it was asked in. --}}
        <ul class="mt-3 space-y-3 border-t border-slate-100 pt-3 dark:border-slate-800">
            @foreach (array_reverse($exchanges, true) as $i => $turn)
                <li wire:key="turn-{{ $i }}">
                    @if ($turn['role'] === 'user')
                        <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ $turn['content'] }}</p>
                    @else
                        <p class="text-[15px] whitespace-pre-line">{{ $turn['content'] }}</p>
                        @if ($turn['used'] !== [])
                            <p class="mt-0.5 text-xs text-slate-400">{{ $this->looked($turn['used']) }}</p>
                        @endif
                    @endif
                </li>
            @endforeach
        </ul>

        <button type="button" wire:click="startAgain"
                class="mt-2 touch-target text-sm font-medium text-slate-400">
            Start again
        </button>
    @endif
</div>
