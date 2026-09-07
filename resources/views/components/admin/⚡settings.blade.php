<?php

use App\Models\Calendar;
use App\Models\Household;
use App\Services\Attribution\EventAttributor;
use App\Support\BuildVersion;
use App\Models\Member;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::app')] class extends Component
{
    public string $householdName = '';

    public int $doneRetentionDays = 30;

    /** Days before a to-do is due that it starts appearing on the wall. */
    public int $todoLeadDays = 7;

    public bool $mirrorDeadlines = false;

    /** @var list<string> */
    public array $mealSlots = ['dinner'];

    public string $mirrorCalendarId = '';

    /** Member being edited, or null when the form is closed. */
    public ?int $editingId = null;

    public string $name = '';

    public string $colour = '#2563eb';

    public bool $isChild = false;

    public string $pin = '';

    /** Comma-separated, because chips are fiddly on a phone keyboard. */
    public string $aliases = '';

    public function mount(): void
    {
        $household = Household::current();

        $this->householdName = $household->name;
        $this->doneRetentionDays = $household->doneRetentionDays();
        $this->todoLeadDays = $household->todoLeadDays();
        $this->mirrorDeadlines = (bool) ($household->settings['mirror_deadline_tasks'] ?? false);
        $this->mirrorCalendarId = (string) ($household->settings['mirror_calendar_id'] ?? '');
        $this->mealSlots = $household->mealSlots();
    }

    #[Computed]
    public function members(): Collection
    {
        return Household::current()->members()->with('aliases')->get();
    }

    /** Calendars a reminder could be written to. */
    #[Computed]
    public function writableCalendars(): Collection
    {
        return Calendar::query()
            ->whereHas('account', fn ($q) => $q->where('household_id', Household::current()->id))
            ->where('is_writable', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function buildVersion(): string
    {
        return BuildVersion::current();
    }

    public function placesSummary(): string
    {
        $places = Household::current()->places()->count();

        return $places === 0
            ? 'Name the places that turn up in event titles, so events find the right person.'
            : trans_choice('{1}:count place|[2,*]:count places', $places, ['count' => $places]).' recognised in event titles.';
    }

    public function homeSummary(): string
    {
        if (! app(\App\Services\HomeAssistant\HomeAssistant::class)->isConfigured()) {
            return 'Not connected — set HA_URL and HA_TOKEN in .env.';
        }

        $tiles = \App\Models\HomeTile::where('household_id', Household::current()->id)->count();

        return $tiles === 0
            ? 'Connected. Choose what belongs on the wall.'
            : trans_choice('{1}:count tile|[2,*]:count tiles', $tiles, ['count' => $tiles]).' on the wall.';
    }

    public function choresSummary(): string
    {
        $chores = \App\Models\Chore::where('household_id', Household::current()->id)->active()->count();

        return $chores === 0
            ? 'Set up the jobs each child does, and what they are worth.'
            : trans_choice('{1}:count chore|[2,*]:count chores', $chores, ['count' => $chores]).' running.';
    }

    public function calendarSummary(): string
    {
        $accounts = Household::current()->calendarAccounts()->withCount('calendars')->get();

        if ($accounts->isEmpty()) {
            return 'No accounts connected yet.';
        }

        $broken = $accounts->where('status', 'error')->count();

        return trans_choice('{1}:count account|[2,*]:count accounts', $accounts->count(), ['count' => $accounts->count()])
            .', '.$accounts->sum('calendars_count').' calendars'
            .($broken ? " · {$broken} needing attention" : '');
    }

    public function saveHousehold(): void
    {
        $this->validate([
            'householdName' => 'required|string|max:120',
            'doneRetentionDays' => 'required|integer|min:1|max:3650',
            'todoLeadDays' => 'required|integer|min:0|max:365',
            'mirrorCalendarId' => 'nullable|integer',
        ]);

        $household = Household::current();

        $household->update(['name' => $this->householdName]);
        $household->setDoneRetentionDays($this->doneRetentionDays);
        $household->setTodoLeadDays($this->todoLeadDays);
        $household->setDeadlineMirror(
            $this->mirrorDeadlines,
            $this->mirrorCalendarId !== '' ? (int) $this->mirrorCalendarId : null,
        );
        $household->setMealSlots($this->mealSlots);

        $this->dispatch('saved', message: 'Household saved.');
    }

    public function edit(int $id): void
    {
        $member = Member::with('aliases')->findOrFail($id);

        $this->editingId = $member->id;
        $this->name = $member->name;
        $this->colour = $member->colour;
        $this->isChild = $member->is_child;
        $this->pin = '';
        $this->aliases = $member->aliases->pluck('alias')->join(', ');
    }

    public function addMember(): void
    {
        $this->reset(['editingId', 'name', 'colour', 'isChild', 'pin', 'aliases']);
        $this->editingId = 0; // 0 means "new"
    }

    public function saveMember(): void
    {
        $this->validate([
            'name' => 'required|string|max:60',
            'colour' => 'required|regex:/^#[0-9a-fA-F]{6}$/',
            'pin' => 'nullable|digits_between:4,6',
        ]);

        $member = $this->editingId
            ? Member::findOrFail($this->editingId)
            : new Member(['household_id' => Household::current()->id]);

        $member->fill([
            'household_id' => Household::current()->id,
            'name' => $this->name,
            'colour' => strtolower($this->colour),
            'is_child' => $this->isChild,
        ]);

        // Leaving the pin blank keeps whatever is already set.
        if ($this->pin !== '') {
            $member->pin = $this->pin;
        }

        $member->save();
        $member->ensureFirstNameAlias();

        $this->syncAliases($member);

        $this->reset(['editingId', 'name', 'colour', 'isChild', 'pin', 'aliases']);
        unset($this->members);

        // Titles that mention this member may now match differently.
        app(EventAttributor::class)->applyToHousehold(Household::current());

        $this->dispatch('saved', message: 'Member saved. Attribution re-run.');
    }

    /** Replace the member's aliases with what was typed, keeping them tidy. */
    protected function syncAliases(Member $member): void
    {
        $wanted = collect(explode(',', $this->aliases))
            ->map(fn (string $a) => trim(preg_replace('/\s+/u', ' ', $a) ?? ''))
            ->filter()
            ->unique(fn (string $a) => mb_strtolower($a))
            ->values();

        $member->aliases()->whereNotIn('alias', $wanted->all())->delete();

        foreach ($wanted as $alias) {
            $member->aliases()->firstOrCreate(['alias' => $alias]);
        }
    }

    public function deleteMember(int $id): void
    {
        Member::where('household_id', Household::current()->id)->findOrFail($id)->delete();

        unset($this->members);
        $this->dispatch('saved', message: 'Member removed.');
    }
}; ?>

<div class="app-shell flex flex-col">
    <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
        <a href="{{ route('app') }}" wire:navigate
           class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m15 18-6-6 6-6" />
            </svg>
        </a>
        <h1 class="text-2xl font-bold">Settings</h1>
    </header>

    <div class="pane-scroll min-h-0 flex-1 space-y-6 px-4 pb-8">

        {{-- Saved toast --}}
        <div x-data="{ show: false, message: '' }"
             x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
             x-show="show" x-cloak x-transition
             class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
            <span x-text="message"></span>
        </div>

        {{-- Household --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <h2 class="font-semibold">Household</h2>
            <form wire:submit="saveHousehold" class="mt-3 flex gap-2">
                <input wire:model="householdName" type="text" aria-label="Household name"
                       class="touch-target min-w-0 flex-1 rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                <button type="submit" class="touch-target shrink-0 rounded-xl bg-blue-600 px-5 font-semibold text-white">Save</button>
            </form>
            @error('householdName') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <label class="mt-3 flex items-center justify-between gap-3">
                <span class="min-w-0">
                    <span class="block text-sm font-medium">Keep completed to-dos for</span>
                    <span class="block text-sm text-slate-500 dark:text-slate-400">
                        Ticked items stay under "Done" this long, then a nightly job removes them.
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    <input wire:model="doneRetentionDays" type="number" inputmode="numeric" min="1" max="3650"
                           aria-label="Days to keep completed to-dos"
                           class="touch-target w-20 rounded-xl border border-slate-300 px-3 text-center dark:border-slate-700 dark:bg-slate-950">
                    <span class="text-sm text-slate-500 dark:text-slate-400">days</span>
                </span>
            </label>
            @error('doneRetentionDays') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <label class="mt-3 flex items-center justify-between gap-3">
                <span class="min-w-0">
                    <span class="block text-sm font-medium">Show dated to-dos from</span>
                    <span class="block text-sm text-slate-500 dark:text-slate-400">
                        How long before its due date a to-do appears on the wall. Earlier ones
                        wait under "Upcoming" on the Lists tab. Any single to-do can override this.
                    </span>
                </span>
                <span class="flex shrink-0 items-center gap-2">
                    <input wire:model="todoLeadDays" type="number" inputmode="numeric" min="0" max="365"
                           aria-label="Days before due to show a to-do"
                           class="touch-target w-20 rounded-xl border border-slate-300 px-3 text-center dark:border-slate-700 dark:bg-slate-950">
                    <span class="text-sm text-slate-500 dark:text-slate-400">days early</span>
                </span>
            </label>
            @error('todoLeadDays') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            {{-- Off by default: it writes to a shared family calendar, which is
                 not something to start doing without being asked. --}}
            <label class="mt-3 flex items-center justify-between gap-3">
                <span class="min-w-0">
                    <span class="block text-sm font-medium">Also add deadline to-dos to the calendar</span>
                    <span class="block text-sm text-slate-500 dark:text-slate-400">
                        Puts an all-day "Reminder: …" in iCloud on the day the to-do appears,
                        so phones see it too. Ticking the to-do removes it again.
                    </span>
                </span>
                <input wire:model.live="mirrorDeadlines" type="checkbox"
                       aria-label="Also add deadline to-dos to the calendar"
                       class="size-6 shrink-0 rounded">
            </label>

            @if ($mirrorDeadlines)
                @if ($this->writableCalendars->isEmpty())
                    <p class="mt-2 text-sm text-amber-600 dark:text-amber-400">
                        Connect a writable iCloud calendar first — there is nowhere to put reminders yet.
                    </p>
                @else
                    <label class="mt-2 block">
                        <span class="block text-sm font-medium">Reminder calendar</span>
                        <select wire:model="mirrorCalendarId"
                                class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950">
                            <option value="">Choose a calendar…</option>
                            @foreach ($this->writableCalendars as $calendar)
                                <option value="{{ $calendar->id }}">{{ $calendar->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            @endif

            {{-- Dinner is not offered as a choice: a meal planner with no rows
                 is not a planner, and every household plans dinner. --}}
            <div class="mt-3">
                <span class="block text-sm font-medium">Meals to plan</span>
                <span class="block text-sm text-slate-500 dark:text-slate-400">
                    Dinner is always shown. Breakfast and lunch appear beside it on the Meals tab.
                </span>
                <div class="mt-2 flex flex-wrap gap-4">
                    @foreach (['breakfast' => 'Breakfast', 'lunch' => 'Lunch'] as $slot => $label)
                        <label class="flex touch-target items-center gap-2">
                            <input type="checkbox" wire:model="mealSlots" value="{{ $slot }}" class="size-5 rounded">
                            <span class="text-sm font-medium">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <button type="button" wire:click="saveHousehold"
                    class="mt-3 w-full touch-target rounded-xl bg-blue-600 font-semibold text-white">
                Save household settings
            </button>

            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                Timezone: {{ Household::current()->displayTimezone() }}
                <span class="text-slate-400">(times are stored in UTC)</span>
            </p>
        </section>

        {{-- Members --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Family members</h2>
                <button type="button" wire:click="addMember" class="touch-target rounded-xl px-3 font-semibold text-blue-600 dark:text-blue-400">Add</button>
            </div>

            <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($this->members as $member)
                    <li class="flex items-center gap-3 py-2" wire:key="member-{{ $member->id }}">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full text-sm font-bold text-white"
                              style="background-color: {{ $member->colour }};">{{ $member->initials() }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ $member->name }}</span>
                            <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                                {{ $member->is_child ? 'Child' : 'Adult' }}@if ($member->is_child && $member->pin) · PIN set @endif
                                @if ($member->aliases->isNotEmpty()) · also {{ $member->aliases->pluck('alias')->join(', ') }} @endif
                            </span>
                        </span>
                        <button type="button" wire:click="edit({{ $member->id }})"
                                class="touch-target rounded-xl px-3 text-sm font-semibold text-blue-600 dark:text-blue-400">Edit</button>
                    </li>
                @endforeach
            </ul>

            @if ($editingId !== null)
                <form wire:submit="saveMember" class="mt-4 space-y-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-950">
                    <div>
                        <label class="block text-sm font-medium" for="member-name">Name</label>
                        <input wire:model="name" id="member-name" type="text"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-900">
                        @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center gap-3">
                        <label class="text-sm font-medium" for="member-colour">Colour</label>
                        <input wire:model="colour" id="member-colour" type="color" class="h-11 w-16 rounded-lg border border-slate-300 dark:border-slate-700">
                        <label class="ml-auto flex touch-target items-center gap-2">
                            <input wire:model="isChild" type="checkbox" class="size-5 rounded">
                            <span class="text-sm font-medium">Child</span>
                        </label>
                    </div>

                    <div>
                        <label class="block text-sm font-medium" for="member-aliases">Also matches</label>
                        <input wire:model="aliases" id="member-aliases" type="text" autocapitalize="words"
                               placeholder="SW, Si"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-900">
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Other names this person goes by in event titles, separated by commas.
                            @if (filled($name))
                                <span class="text-slate-400">"{{ $name }}" always matches.</span>
                            @endif
                        </p>
                        @error('aliases') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    {{-- Adults need one too: granting a reward at the wall
                         asks for a grown-up's PIN, and the wall has no login. --}}
                    <div>
                        <label class="block text-sm font-medium" for="member-pin">PIN (leave blank to keep)</label>
                        <input wire:model="pin" id="member-pin" type="text" inputmode="numeric" autocomplete="off"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 tracking-widest dark:border-slate-700 dark:bg-slate-900">
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ $isChild
                                ? 'Asked for when spending points, or undoing something a grown-up checked. Never for ticking their own chores.'
                                : 'Asked for when handing over a reward at the wall.' }}
                        </p>
                        @error('pin') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">Save</button>
                        <button type="button" wire:click="$set('editingId', null)" class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                        @if ($editingId)
                            <button type="button" wire:click="deleteMember({{ $editingId }})"
                                    wire:confirm="Remove this member? Their calendars stay, but become unassigned."
                                    class="touch-target rounded-xl px-4 font-semibold text-red-600">Delete</button>
                        @endif
                    </div>
                </form>
            @endif
        </section>

        {{-- Places --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Schools, work and clubs</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ $this->placesSummary() }}
                    </p>
                </div>
                <a href="{{ route('admin.places') }}" wire:navigate
                   class="grid touch-target shrink-0 place-items-center rounded-xl px-4 font-semibold text-blue-600 dark:text-blue-400">
                    Manage
                </a>
            </div>
        </section>

        {{-- Kids --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Chores</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ $this->choresSummary() }}
                    </p>
                </div>
                <a href="{{ route('admin.chores') }}" wire:navigate
                   class="grid touch-target shrink-0 place-items-center rounded-xl px-4 font-semibold text-blue-600 dark:text-blue-400">
                    Manage
                </a>
            </div>
        </section>

        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Routines</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Morning, after-school and bedtime checklists, shown on the wall during their window.
                    </p>
                </div>
                <a href="{{ route('admin.routines') }}" wire:navigate
                   class="grid touch-target shrink-0 place-items-center rounded-xl px-4 font-semibold text-blue-600 dark:text-blue-400">
                    Manage
                </a>
            </div>
        </section>

        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Rewards</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        What points can be spent on, and whether they are worth pocket money.
                    </p>
                </div>
                <a href="{{ route('admin.rewards') }}" wire:navigate
                   class="grid touch-target shrink-0 place-items-center rounded-xl px-4 font-semibold text-blue-600 dark:text-blue-400">
                    Manage
                </a>
            </div>
        </section>

        {{-- Smart home --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">Home Assistant</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ $this->homeSummary() }}
                    </p>
                </div>
                <a href="{{ route('admin.home') }}" wire:navigate
                   class="grid touch-target shrink-0 place-items-center rounded-xl px-4 font-semibold text-blue-600 dark:text-blue-400">
                    Manage
                </a>
            </div>
        </section>

        {{-- Wall display --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <h2 class="font-semibold">Wall display</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Pair the iPad by opening the display URL on it once. Get the URL with
                <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">php artisan familyhub:display-token</code>.
            </p>
            <dl class="mt-3 space-y-1 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Dark mode</dt>
                    <dd class="tabular-nums">{{ config('familyhub.dark_mode.start') }} – {{ config('familyhub.dark_mode.end') }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Screensaver after</dt>
                    <dd>{{ config('familyhub.screensaver.idle_minutes') ?: 'never' }} @if (config('familyhub.screensaver.idle_minutes')) min @endif</dd>
                </div>
            </dl>
            <a href="{{ route('display') }}" wire:navigate class="mt-3 inline-flex touch-target items-center font-semibold text-blue-600 dark:text-blue-400">
                Preview the wall display
            </a>
        </section>

        {{-- About --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <h2 class="font-semibold">About</h2>
            <dl class="mt-2 space-y-1 text-sm">
                <div class="flex items-baseline justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Build</dt>
                    <dd class="font-mono text-xs" data-build-version>{{ $this->buildVersion }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Laravel</dt>
                    <dd>{{ app()->version() }}</dd>
                </div>
            </dl>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                The wall display checks this every minute and reloads itself after a deploy,
                once nobody has touched the screen for 30 seconds.
            </p>
        </section>

        {{-- Calendars --}}
        <section class="rounded-2xl bg-white p-4 dark:bg-slate-900">
            <div class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="font-semibold">iCloud calendars</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ $this->calendarSummary() }}
                    </p>
                </div>
                <a href="{{ route('admin.calendars') }}" wire:navigate
                   class="grid touch-target shrink-0 place-items-center rounded-xl px-4 font-semibold text-blue-600 dark:text-blue-400">
                    Manage
                </a>
            </div>
        </section>
    </div>
</div>
