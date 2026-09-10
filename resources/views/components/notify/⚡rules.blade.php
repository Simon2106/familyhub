<?php

use App\Models\Calendar;
use App\Models\Event;
use App\Models\Household;
use App\Models\Member;
use App\Models\Place;
use App\Models\ReminderRule;
use App\Services\Notifications\DueReminder;
use App\Services\Notifications\ReminderEngine;
use App\Services\Notifications\ReminderTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Reminders somebody builds, rather than one lead time for everything.
 *
 * Three questions — who, what, when — and a preview of the next three
 * notifications the answers would produce. The preview is the part that
 * matters: a rule is a small program, and nobody should have to wait until
 * Tuesday to find out whether they wrote it correctly.
 *
 * The preview asks ReminderEngine the same question the scheduler asks, of a
 * rule object that has not been saved. A preview computed by different code
 * from the thing it previews is a preview that eventually lies.
 */
new class extends Component
{
    /** How many events to offer when picking one by hand. */
    public const EVENT_CHOICES = 40;

    public bool $editing = false;

    public ?int $editingId = null;

    public string $name = '';

    public bool $active = true;

    public string $scope = 'all';

    public ?int $calendarId = null;

    public ?int $placeId = null;

    public ?int $eventId = null;

    public bool $includeRepeats = true;

    public string $keyword = '';

    /** @var list<int> */
    public array $members = [];

    /** @var list<string> */
    public array $times = ['before:60'];

    public string $customMinutes = '';

    public ?string $problem = null;

    /** Narrows the event picker, which is otherwise a fortnight of everything. */
    public string $eventSearch = '';

    public function household(): Household
    {
        return Household::current();
    }

    /* ------------------------------ the list ---------------------------- */

    /** @return Collection<int, ReminderRule> */
    #[Computed]
    public function rules(): Collection
    {
        return ReminderRule::query()
            ->where('user_id', auth()->id())
            ->with(['calendar', 'place', 'event'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Member> */
    #[Computed]
    public function people(): Collection
    {
        return $this->household()->members()->orderBy('sort_order')->orderBy('name')->get();
    }

    /** @return Collection<int, Calendar> */
    #[Computed]
    public function calendars(): Collection
    {
        return Calendar::query()
            ->whereHas('account', fn ($q) => $q->where('household_id', $this->household()->id))
            ->where('is_visible', true)
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Place> */
    #[Computed]
    public function places(): Collection
    {
        return $this->household()->places()->orderBy('name')->get();
    }

    /**
     * Events to pick one from: what is coming, soonest first.
     *
     * A repeating event sits at its first occurrence, which may be last year,
     * so those are offered too rather than filtered out by the date window.
     *
     * @return Collection<int, Event>
     */
    #[Computed]
    public function choosableEvents(): Collection
    {
        $now = CarbonImmutable::now();

        return Event::query()
            ->notCancelled()
            ->whereHas('calendar', fn ($q) => $q
                ->where('is_visible', true)
                ->whereHas('account', fn ($a) => $a->where('household_id', $this->household()->id)))
            ->where(fn ($q) => $q
                ->whereBetween('start_at', [$now, $now->addMonths(3)])
                ->orWhereNotNull('rrule'))
            ->when(trim($this->eventSearch) !== '', fn ($q) => $q
                ->where('title', 'like', '%'.str_replace(['%', '_'], '', trim($this->eventSearch)).'%'))
            ->orderBy('start_at')
            ->limit(self::EVENT_CHOICES)
            ->get();
    }

    /* ------------------------------ editing ----------------------------- */

    public function add(): void
    {
        $this->reset([
            'editingId', 'name', 'scope', 'calendarId', 'placeId', 'eventId',
            'keyword', 'members', 'customMinutes', 'problem', 'eventSearch',
        ]);

        $this->active = true;
        $this->includeRepeats = true;
        $this->times = ['before:60'];
        $this->editing = true;

        unset($this->preview);
    }

    public function edit(int $id): void
    {
        $rule = $this->find($id);

        if (! $rule) {
            return;
        }

        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->active = (bool) $rule->is_active;
        $this->scope = $rule->scope;
        $this->calendarId = $rule->calendar_id;
        $this->placeId = $rule->place_id;
        $this->eventId = $rule->event_id;
        $this->keyword = (string) $rule->keyword;
        $this->includeRepeats = (bool) $rule->include_repeats;
        $this->members = $rule->memberIds();
        $this->times = array_map(fn (ReminderTime $t) => $t->toString(), $rule->reminderTimes());
        $this->customMinutes = '';
        $this->problem = null;
        $this->editing = true;

        unset($this->preview);
    }

    public function toggleMember(int $id): void
    {
        $this->members = in_array($id, $this->members, true)
            ? array_values(array_diff($this->members, [$id]))
            : [...$this->members, $id];

        unset($this->preview);
    }

    public function toggleTime(string $value): void
    {
        $this->times = in_array($value, $this->times, true)
            ? array_values(array_diff($this->times, [$value]))
            : [...$this->times, $value];

        unset($this->preview);
    }

    public function addCustomTime(): void
    {
        $minutes = (int) trim($this->customMinutes);

        if ($minutes <= 0 || $minutes > ReminderTime::MAX_MINUTES) {
            $this->problem = 'Give a number of minutes between 1 and '.ReminderTime::MAX_MINUTES.'.';

            return;
        }

        $this->problem = null;
        $this->customMinutes = '';

        $this->toggleTime(ReminderTime::before($minutes)->toString());
    }

    /** Recomputed whenever anything the rule is made of changes. */
    public function updated(string $property): void
    {
        if (str_starts_with($property, 'custom') || $property === 'eventSearch') {
            return;
        }

        unset($this->preview);
    }

    /**
     * The rule as it stands on screen, saved or not.
     *
     * Deliberately an unsaved model: the preview is of what is being edited,
     * not of what is in the database.
     */
    public function draft(): ReminderRule
    {
        $rule = new ReminderRule([
            'user_id' => auth()->id(),
            'household_id' => $this->household()->id,
            'name' => trim($this->name),
            'is_active' => true,
            'scope' => $this->scope,
            'calendar_id' => $this->scope === 'calendar' ? $this->calendarId : null,
            'place_id' => $this->scope === 'place' ? $this->placeId : null,
            'event_id' => $this->scope === 'event' ? $this->eventId : null,
            'keyword' => $this->scope === 'keyword' ? trim($this->keyword) : null,
            'include_repeats' => $this->scope === 'event' ? $this->includeRepeats : false,
            'members' => array_values($this->members),
            'times' => array_values($this->times),
        ]);

        // The engine reads these through the relations, and an unsaved model
        // has none — so they are set by hand rather than left null.
        $rule->setRelation('calendar', $rule->calendar_id ? $this->calendars->firstWhere('id', $rule->calendar_id) : null);
        $rule->setRelation('place', $rule->place_id ? $this->places->firstWhere('id', $rule->place_id) : null);
        $rule->setRelation('event', $rule->event_id ? Event::find($rule->event_id) : null);

        return $rule;
    }

    /**
     * The next three, so it can be checked before it is trusted.
     *
     * @return list<DueReminder>
     */
    #[Computed]
    public function preview(): array
    {
        $rule = $this->draft();

        if ($rule->isBroken()) {
            return [];
        }

        return app(ReminderEngine::class)->preview($rule, $this->household(), 3);
    }

    public function save(): void
    {
        $this->problem = null;
        $rule = $this->draft();

        if ($this->times === []) {
            $this->problem = 'Choose at least one time to be told.';

            return;
        }

        if ($rule->isBroken()) {
            $this->problem = 'That rule is not finished — pick what it should match.';

            return;
        }

        ReminderRule::updateOrCreate(
            ['id' => $this->editingId, 'user_id' => auth()->id()],
            [
                'household_id' => $this->household()->id,
                'name' => trim($this->name) !== '' ? trim($this->name) : $this->suggestedName(),
                'is_active' => $this->active,
                'scope' => $rule->scope,
                'calendar_id' => $rule->calendar_id,
                'place_id' => $rule->place_id,
                'event_id' => $rule->event_id,
                'keyword' => $rule->keyword,
                'include_repeats' => $rule->include_repeats,
                'members' => $rule->members,
                'times' => $rule->times,
            ],
        );

        $this->editing = false;

        unset($this->rules, $this->preview);

        $this->dispatch('saved', message: 'Reminder saved.');
    }

    /** A rule with no name still needs one in the list. */
    protected function suggestedName(): string
    {
        return match ($this->scope) {
            'calendar' => $this->calendars->firstWhere('id', $this->calendarId)?->name ?? 'Calendar',
            'place' => $this->places->firstWhere('id', $this->placeId)?->name ?? 'Place',
            'keyword' => '“'.trim($this->keyword).'”',
            'event' => Event::find($this->eventId)?->title ?? 'One event',
            default => 'Everything',
        };
    }

    public function toggleActive(int $id): void
    {
        if ($rule = $this->find($id)) {
            $rule->forceFill(['is_active' => ! $rule->is_active])->save();
        }

        unset($this->rules);
    }

    public function remove(int $id): void
    {
        $this->find($id)?->delete();

        $this->editing = false;

        unset($this->rules, $this->preview);

        $this->dispatch('saved', message: 'Reminder removed.');
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->problem = null;

        unset($this->preview);
    }

    /** Somebody made a one-off from an event while this page was open. */
    #[On('reminder-rules-changed')]
    public function refreshRules(): void
    {
        unset($this->rules, $this->preview);
    }

    /** Scoped to the signed-in person: rules are theirs, not the household's. */
    protected function find(int $id): ?ReminderRule
    {
        return ReminderRule::where('user_id', auth()->id())->find($id);
    }
}; ?>

<section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
    <div class="flex items-center gap-2">
        <h2 class="flex-1 font-semibold">Reminders about events</h2>
        @unless ($editing)
            <button type="button" wire:click="add"
                    class="touch-target rounded-xl bg-slate-100 px-4 text-sm font-semibold dark:bg-slate-800">
                Add a reminder
            </button>
        @endunless
    </div>

    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        Who, what and when. As many as you like — nothing is sent unless
        “Reminders about events” is switched on below.
    </p>

    {{-- ------------------------------ the list ------------------------- --}}
    @forelse ($this->rules as $rule)
        <div class="mt-3 flex items-start gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60"
             wire:key="rule-{{ $rule->id }}">
            <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2 truncate font-semibold">
                    {{ $rule->name }}
                    @if ($rule->is_one_off)
                        <span class="shrink-0 rounded-lg bg-slate-200 px-1.5 text-xs font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300">one-off</span>
                    @endif
                </p>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ $rule->summary() }}</p>
                @if ($rule->isBroken())
                    <p class="mt-1 text-sm font-medium text-amber-700 dark:text-amber-400">
                        This one cannot match anything any more — edit or remove it.
                    </p>
                @endif
            </div>

            <div class="flex shrink-0 items-center gap-1">
                <button type="button" wire:click="toggleActive({{ $rule->id }})"
                        class="touch-target rounded-xl px-3 text-sm font-semibold {{ $rule->is_active ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-400' }}"
                        aria-label="{{ $rule->is_active ? 'Turn off' : 'Turn on' }} {{ $rule->name }}">
                    {{ $rule->is_active ? 'On' : 'Off' }}
                </button>
                <button type="button" wire:click="edit({{ $rule->id }})"
                        class="touch-target rounded-xl px-3 text-sm font-semibold text-slate-600 dark:text-slate-300">Edit</button>
            </div>
        </div>
    @empty
        @unless ($editing)
            <p class="mt-3 text-sm text-slate-400">No reminders yet.</p>
        @endunless
    @endforelse

    {{-- ------------------------------ the editor ----------------------- --}}
    @if ($editing)
        <div class="mt-3 space-y-4 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
            <label class="block">
                <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">Name</span>
                <input type="text" wire:model.blur="name" maxlength="80" placeholder="Sienna’s school things"
                       class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-800" />
            </label>

            {{-- ------------------------------ who ---------------------- --}}
            <div>
                <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">Who</span>
                <div class="mt-1 flex flex-wrap gap-2">
                    <button type="button" wire:click="$set('members', [])"
                            class="touch-target rounded-xl px-4 text-sm font-semibold {{ $members === [] ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-800' }}">
                        Anyone
                    </button>
                    @foreach ($this->people as $person)
                        <button type="button" wire:click="toggleMember({{ $person->id }})" wire:key="who-{{ $person->id }}"
                                class="touch-target rounded-xl px-4 text-sm font-semibold {{ in_array($person->id, $members, true) ? 'text-white' : 'bg-slate-100 dark:bg-slate-800' }}"
                                @style(['background-color: '.$person->colour => in_array($person->id, $members, true)])>
                            {{ $person->name }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- ------------------------------ what --------------------- --}}
            <div>
                <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">What</span>
                <div class="mt-1 flex flex-wrap gap-2">
                    @foreach (ReminderRule::SCOPES as $value => $label)
                        <button type="button" wire:click="$set('scope', '{{ $value }}')" wire:key="scope-{{ $value }}"
                                class="touch-target rounded-xl px-4 text-sm font-semibold {{ $scope === $value ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                @if ($scope === 'calendar')
                    <select wire:model.live="calendarId"
                            class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Choose a calendar…</option>
                        @foreach ($this->calendars as $calendar)
                            <option value="{{ $calendar->id }}">{{ $calendar->name }}</option>
                        @endforeach
                    </select>
                @elseif ($scope === 'place')
                    <select wire:model.live="placeId"
                            class="mt-2 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Choose a place…</option>
                        @foreach ($this->places as $place)
                            <option value="{{ $place->id }}">{{ $place->name }} ({{ $place->typeLabel() }})</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">
                        Matched against the event’s title and location, using the names this place answers to.
                    </p>
                @elseif ($scope === 'keyword')
                    <input type="text" wire:model.live.debounce.400ms="keyword" maxlength="80" placeholder="dentist"
                           class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-800" />
                @elseif ($scope === 'event')
                    <input type="search" wire:model.live.debounce.400ms="eventSearch" placeholder="Search the calendar…"
                           class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-800" />

                    <select wire:model.live="eventId" size="5"
                            class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-800">
                        <option value="">Choose an event…</option>
                        @foreach ($this->choosableEvents as $event)
                            <option value="{{ $event->id }}">
                                {{ $event->start_at->timezone($this->household()->displayTimezone())->format('j M H:i') }}
                                — {{ $event->title }}{{ $event->rrule ? ' (repeats)' : '' }}
                            </option>
                        @endforeach
                    </select>

                    <label class="mt-2 flex touch-target items-center gap-3">
                        <input type="checkbox" wire:model.live="includeRepeats" class="size-6 shrink-0 rounded">
                        <span class="text-sm font-medium">And future repeats of it</span>
                    </label>
                @endif
            </div>

            {{-- ------------------------------ when --------------------- --}}
            <div>
                <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">When — as many as you like</span>
                <div class="mt-1 flex flex-wrap gap-2">
                    @foreach (ReminderTime::PRESETS as $value => $label)
                        <button type="button" wire:click="toggleTime('{{ $value }}')" wire:key="time-{{ $value }}"
                                class="touch-target rounded-xl px-4 text-sm font-semibold {{ in_array($value, $times, true) ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800' }}">
                            {{ $label }}
                        </button>
                    @endforeach

                    {{-- Anything already chosen that is not one of the presets:
                         a custom rule has to be visible to be removable. --}}
                    @foreach ($times as $value)
                        @continue (array_key_exists($value, ReminderTime::PRESETS))
                        @php $custom = ReminderTime::parse($value); @endphp
                        @if ($custom)
                            <button type="button" wire:click="toggleTime('{{ $value }}')" wire:key="custom-{{ $value }}"
                                    class="touch-target rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">
                                {{ $custom->label() }} ×
                            </button>
                        @endif
                    @endforeach
                </div>

                <div class="mt-2 flex items-end gap-2">
                    <label class="block">
                        <span class="text-xs text-slate-400">Custom, in minutes</span>
                        <input type="number" inputmode="numeric" min="1" max="{{ ReminderTime::MAX_MINUTES }}"
                               wire:model="customMinutes" wire:keydown.enter.prevent="addCustomTime"
                               class="mt-1 h-11 w-32 rounded-xl border border-slate-200 bg-white px-3 dark:border-slate-700 dark:bg-slate-800" />
                    </label>
                    <button type="button" wire:click="addCustomTime"
                            class="touch-target rounded-xl bg-slate-100 px-4 text-sm font-semibold dark:bg-slate-800">Add</button>
                </div>
            </div>

            {{-- ------------------------------ preview ------------------ --}}
            <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                <h3 class="text-sm font-semibold">The next three</h3>

                @if ($this->preview === [])
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Nothing in the calendar matches this yet — which may be right, or may mean the rule
                        is not finished.
                    </p>
                @else
                    <ul class="mt-1 space-y-1">
                        @foreach ($this->preview as $due)
                            <li class="text-sm" wire:key="prev-{{ $loop->index }}">
                                <span class="font-medium">{{ $due->event->title }}</span>
                                <span class="text-slate-500 dark:text-slate-400">
                                    — {{ $due->whenWords($this->household()->displayTimezone()) }},
                                    told {{ $due->at->timezone($this->household()->displayTimezone())->format('D j M H:i') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($problem)
                <p class="text-sm font-medium text-rose-600 dark:text-rose-400">{{ $problem }}</p>
            @endif

            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="save"
                        class="touch-target rounded-xl bg-blue-600 px-6 font-semibold text-white">Save</button>
                <button type="button" wire:click="cancel"
                        class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                @if ($editingId)
                    <button type="button" wire:click="remove({{ $editingId }})"
                            wire:confirm="Remove this reminder?"
                            class="touch-target ml-auto rounded-xl px-4 font-semibold text-rose-600 dark:text-rose-400">Remove</button>
                @endif
            </div>
        </div>
    @endif
</section>
