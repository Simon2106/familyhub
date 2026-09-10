<?php

use App\Models\Event;
use App\Models\Household;
use App\Models\ReminderRule;
use App\Services\Notifications\DueReminder;
use App\Services\Notifications\ReminderEngine;
use App\Services\Notifications\ReminderTime;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Remind me about this one", from wherever an event is being looked at.
 *
 * It makes an ordinary rule scoped to a single event, rather than a second
 * kind of reminder with its own storage and its own bugs. The one-off flag
 * exists only so the rules list can say which are which — everything else
 * about it, including how it fires, is the same code.
 */
new class extends Component
{
    public ?int $eventId = null;

    /** @var list<string> */
    public array $times = ['before:60'];

    public bool $includeRepeats = false;

    public string $customMinutes = '';

    public ?string $problem = null;

    public bool $saved = false;

    #[On('remind-me')]
    public function open(int $event): void
    {
        $this->reset(['times', 'customMinutes', 'problem', 'saved', 'includeRepeats']);

        $this->times = ['before:60'];
        $this->eventId = $event;

        unset($this->event, $this->existing, $this->preview);
    }

    public function close(): void
    {
        $this->reset(['eventId', 'problem', 'saved']);

        unset($this->event, $this->existing, $this->preview);
    }

    public function household(): Household
    {
        return Household::current();
    }

    /** Scoped to the household, because the id arrives from the browser. */
    #[Computed]
    public function event(): ?Event
    {
        return $this->eventId
            ? Event::query()
                ->whereHas('calendar', fn ($q) => $q
                    ->whereHas('account', fn ($a) => $a->where('household_id', $this->household()->id)))
                ->with('calendar')
                ->find($this->eventId)
            : null;
    }

    /** A reminder already set on this event, so the dialog can offer to undo it. */
    #[Computed]
    public function existing(): ?ReminderRule
    {
        return $this->eventId
            ? ReminderRule::where('user_id', auth()->id())
                ->where('scope', 'event')
                ->where('event_id', $this->eventId)
                ->where('is_one_off', true)
                ->first()
            : null;
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

    public function updatedIncludeRepeats(): void
    {
        unset($this->preview);
    }

    protected function draft(): ReminderRule
    {
        $event = $this->event;

        $rule = new ReminderRule([
            'user_id' => auth()->id(),
            'household_id' => $this->household()->id,
            'name' => $event?->title ?? 'Reminder',
            'is_active' => true,
            'scope' => 'event',
            'event_id' => $this->eventId,
            'include_repeats' => $this->includeRepeats,
            'members' => [],
            'times' => array_values($this->times),
            'is_one_off' => true,
        ]);

        $rule->setRelation('event', $event);

        return $rule;
    }

    /** @return list<DueReminder> */
    #[Computed]
    public function preview(): array
    {
        if (! $this->event || $this->times === []) {
            return [];
        }

        return app(ReminderEngine::class)->preview($this->draft(), $this->household(), 3);
    }

    public function save(): void
    {
        $this->problem = null;

        if (! $this->event) {
            return;
        }

        if ($this->times === []) {
            $this->problem = 'Choose at least one time to be told.';

            return;
        }

        $rule = $this->draft();

        ReminderRule::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'scope' => 'event',
                'event_id' => $this->eventId,
                'is_one_off' => true,
            ],
            [
                'household_id' => $rule->household_id,
                'name' => $rule->name,
                'is_active' => true,
                'members' => [],
                'include_repeats' => $rule->include_repeats,
                'times' => $rule->times,
            ],
        );

        $this->saved = true;

        unset($this->existing);

        // The rules list may be open on the same page.
        $this->dispatch('reminder-rules-changed');
        $this->dispatch('saved', message: 'Reminder set.');
    }

    public function forget(): void
    {
        $this->existing?->delete();

        unset($this->existing, $this->preview);

        $this->dispatch('reminder-rules-changed');
        $this->dispatch('saved', message: 'Reminder removed.');

        $this->close();
    }
}; ?>

<div>
    @if ($this->event)
        <x-modal dismiss="close">
            <x-slot:header>
                <h2 class="truncate text-lg font-bold">Remind me</h2>
                <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $this->event->title }}</p>
            </x-slot:header>

            <div class="space-y-4">
                @if ($this->existing)
                    <p class="rounded-xl bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        You already have a reminder on this one. Saving replaces it.
                    </p>
                @endif

                <div>
                    <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">When — as many as you like</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach (ReminderTime::PRESETS as $value => $label)
                            <button type="button" wire:click="toggleTime('{{ $value }}')" wire:key="rt-{{ $value }}"
                                    class="touch-target rounded-xl px-4 text-sm font-semibold {{ in_array($value, $times, true) ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800' }}">
                                {{ $label }}
                            </button>
                        @endforeach

                        @foreach ($times as $value)
                            @continue (array_key_exists($value, ReminderTime::PRESETS))
                            @php $custom = ReminderTime::parse($value); @endphp
                            @if ($custom)
                                <button type="button" wire:click="toggleTime('{{ $value }}')" wire:key="rc-{{ $value }}"
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

                @if ($this->event->rrule)
                    <label class="flex touch-target items-center gap-3">
                        <input type="checkbox" wire:model.live="includeRepeats" class="size-6 shrink-0 rounded">
                        <span class="text-sm font-medium">And future repeats of it</span>
                    </label>
                @endif

                <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                    <h3 class="text-sm font-semibold">The next three</h3>

                    @if ($this->preview === [])
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Nothing yet — this event may already have started.
                        </p>
                    @else
                        <ul class="mt-1 space-y-1">
                            @foreach ($this->preview as $due)
                                <li class="text-sm text-slate-500 dark:text-slate-400" wire:key="rp-{{ $loop->index }}">
                                    {{ $due->at->timezone($this->household()->displayTimezone())->format('D j M H:i') }}
                                    — {{ mb_strtolower($due->time->label()) }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                @if ($problem)
                    <p class="text-sm font-medium text-rose-600 dark:text-rose-400">{{ $problem }}</p>
                @endif

                @if ($saved)
                    <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                        Saved. It is in your reminders list as a one-off.
                    </p>
                @endif
            </div>

            <x-slot:footer>
                <div class="flex gap-2">
                    @if ($this->existing)
                        <button type="button" wire:click="forget"
                                class="touch-target rounded-xl px-4 font-semibold text-rose-600 dark:text-rose-400">
                            Remove it
                        </button>
                    @endif
                    <button type="button" wire:click="close"
                            class="touch-target ml-auto rounded-xl px-4 font-semibold text-slate-500">Close</button>
                    <button type="button" wire:click="save"
                            class="touch-target rounded-xl bg-blue-600 px-6 font-semibold text-white">Remind me</button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
