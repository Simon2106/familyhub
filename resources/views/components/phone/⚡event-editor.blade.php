<?php

use App\Exceptions\CalDavException;
use App\Models\Calendar;
use App\Models\CalendarAccount;
use App\Models\Event;
use App\Models\Household;
use App\Services\Attribution\EventAttributor;
use App\Services\CalDav\CalDavManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Create, edit and delete events, writing straight through to iCloud.
 *
 * Every change goes via EventWriter, so nothing is stored locally that has not
 * been accepted by the server — and a concurrent edit on someone's phone is
 * refused by ETag rather than silently overwritten.
 */
new class extends Component
{
    public bool $open = false;

    public ?int $eventId = null;

    public ?int $calendarId = null;

    public string $title = '';

    public string $date = '';

    public string $startTime = '09:00';

    public string $endTime = '10:00';

    public bool $allDay = false;

    public string $location = '';

    public string $notes = '';

    public ?string $error = null;

    /** Member ids the user has ticked. */
    public array $memberIds = [];

    /** True once the user changes the members, which pins them against syncs. */
    public bool $membersTouched = false;

    /** Whether this event's members were pinned by hand. */
    public bool $isManual = false;

    /** Only calendars on a real iCloud account can be written to. */
    #[Computed]
    public function writableCalendars(): Collection
    {
        return Calendar::query()
            ->whereHas('account', fn ($q) => $q
                ->where('household_id', Household::current()->id)
                ->where('provider', CalendarAccount::PROVIDER_ICLOUD))
            ->where('is_writable', true)
            ->where('is_visible', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function householdMembers(): Collection
    {
        return Household::current()->members;
    }

    public function updatedMemberIds(): void
    {
        $this->membersTouched = true;
    }

    #[On('edit-event')]
    public function edit(?int $eventId = null): void
    {
        $this->reset(['error', 'memberIds', 'membersTouched', 'isManual']);

        $timezone = Household::current()->displayTimezone();

        if ($eventId === null) {
            $this->eventId = null;
            $this->calendarId = $this->writableCalendars()->first()?->id;
            $this->title = '';
            $this->date = Household::current()->todayLocal()->toDateString();
            $this->startTime = '09:00';
            $this->endTime = '10:00';
            $this->allDay = false;
            $this->location = '';
            $this->notes = '';
            $this->memberIds = [];
            $this->open = true;

            return;
        }

        $event = $this->findEvent($eventId);

        $this->eventId = $event->id;
        $this->calendarId = $event->calendar_id;
        $this->title = $event->title;
        // Shown in household time; converted back to UTC on save.
        $this->date = $event->start_at->timezone($timezone)->toDateString();
        $this->startTime = $event->start_at->timezone($timezone)->format('H:i');
        $this->endTime = $event->end_at->timezone($timezone)->format('H:i');
        $this->allDay = $event->all_day;
        $this->location = (string) $event->location;
        $this->notes = (string) $event->notes;
        $this->memberIds = $event->members->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->isManual = $event->attributionIsManual();
        $this->open = true;
    }

    public function save(): void
    {
        $this->validate([
            'calendarId' => 'required|integer',
            'title' => 'required|string|max:200',
            'date' => 'required|date',
            'startTime' => 'required|date_format:H:i',
            'endTime' => 'required|date_format:H:i',
            'location' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:2000',
        ]);

        $calendar = $this->writableCalendars()->firstWhere('id', $this->calendarId);

        if (! $calendar) {
            $this->error = 'That calendar is no longer writable.';

            return;
        }

        $timezone = Household::current()->displayTimezone();

        $start = CarbonImmutable::parse("{$this->date} {$this->startTime}", $timezone);
        $end = CarbonImmutable::parse("{$this->date} {$this->endTime}", $timezone);

        // A finish time before the start almost always means it runs past
        // midnight rather than that the user typed it backwards.
        if ($end <= $start) {
            $end = $end->addDay();
        }

        $attributes = [
            'title' => $this->title,
            'start_at' => $this->allDay ? $start->startOfDay() : $start,
            'end_at' => $this->allDay ? $start->endOfDay() : $end,
            'all_day' => $this->allDay,
            'location' => $this->location ?: null,
            'notes' => $this->notes ?: null,
        ];

        $writer = app(CalDavManager::class)->writer($calendar->account);

        try {
            $event = $this->eventId
                ? $writer->update($this->findEvent($this->eventId), $attributes)
                : $writer->create($calendar, $attributes);
        } catch (CalDavException $e) {
            $this->error = $e->getMessage();

            return;
        }

        // Only pin the members when the user actually changed them; otherwise
        // leave the event under automatic attribution so later edits to
        // aliases and places keep improving it.
        if ($this->membersTouched) {
            app(EventAttributor::class)->setManually(
                $event,
                collect($this->memberIds)->map(fn ($id) => (int) $id)->all(),
            );
        }

        $this->open = false;
        $this->dispatch('events-changed');
        $this->dispatch('saved', message: 'Saved to iCloud.');
    }

    /** Hand an event back to automatic matching. */
    public function useAutomaticMembers(): void
    {
        if (! $this->eventId) {
            return;
        }

        $event = $this->findEvent($this->eventId);

        app(EventAttributor::class)->resetToAutomatic($event);

        $this->memberIds = $event->fresh()->members->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->membersTouched = false;
        $this->isManual = false;

        $this->dispatch('events-changed');
        $this->dispatch('saved', message: 'Back to matching by title.');
    }

    public function deleteEvent(): void
    {
        if (! $this->eventId) {
            return;
        }

        $event = $this->findEvent($this->eventId);

        try {
            app(CalDavManager::class)->writer($event->calendar->account)->delete($event);
        } catch (CalDavException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->open = false;
        $this->dispatch('events-changed');
        $this->dispatch('saved', message: 'Deleted from iCloud.');
    }

    protected function findEvent(int $id): Event
    {
        return Event::whereHas('calendar.account', fn ($q) => $q->where('household_id', Household::current()->id))
            ->findOrFail($id);
    }
}; ?>

<div>
    @if ($open)
        {{-- Was a bottom sheet pinned to the layout viewport, which on iOS is
             underneath the keyboard: ten of its controls, Save included, sat
             off-screen the moment a field was focused. --}}
        <x-modal dismiss="$set('open', false)" :label="$eventId ? 'Edit event' : 'New event'">
            <div class="p-4">
            <h2 class="mb-3 text-lg font-semibold">{{ $eventId ? 'Edit event' : 'New event' }}</h2>

            @if ($this->writableCalendars->isEmpty())
                <p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-900/20 dark:text-amber-200">
                    Connect an iCloud account in Settings before adding events.
                </p>
            @else
                <form wire:submit="save" class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium" for="ev-title">What</label>
                        <input wire:model="title" id="ev-title" type="text" autofocus
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                        @error('title') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium" for="ev-calendar">Calendar</label>
                        <select wire:model="calendarId" id="ev-calendar"
                                class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-3 dark:border-slate-700 dark:bg-slate-950">
                            @foreach ($this->writableCalendars as $calendar)
                                <option value="{{ $calendar->id }}">
                                    {{ $calendar->name }}@if ($calendar->member) — {{ $calendar->member->name }} @endif
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium" for="ev-date">When</label>
                        <input wire:model="date" id="ev-date" type="date"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                        @error('date') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <label class="flex touch-target items-center gap-3">
                        <input wire:model.live="allDay" type="checkbox" class="size-5 rounded">
                        <span class="text-sm font-medium">All day</span>
                    </label>

                    @if (! $allDay)
                        <div class="flex gap-3">
                            <div class="flex-1">
                                <label class="block text-sm font-medium" for="ev-start">From</label>
                                <input wire:model="startTime" id="ev-start" type="time"
                                       class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                            </div>
                            <div class="flex-1">
                                <label class="block text-sm font-medium" for="ev-end">To</label>
                                <input wire:model="endTime" id="ev-end" type="time"
                                       class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                            </div>
                        </div>
                    @endif

                    <fieldset>
                        <div class="flex items-baseline justify-between gap-3">
                            <legend class="text-sm font-medium">Who it's for</legend>
                            @if ($eventId && ! $isManual)
                                <span class="text-xs text-slate-400">matched from the title</span>
                            @elseif ($isManual)
                                <button type="button" wire:click="useAutomaticMembers"
                                        class="text-xs font-semibold text-blue-600 dark:text-blue-400">
                                    Match from the title instead
                                </button>
                            @endif
                        </div>

                        <div class="mt-1 flex flex-wrap gap-2">
                            @foreach ($this->householdMembers as $member)
                                @php $on = in_array((string) $member->id, $memberIds, true); @endphp
                                <label class="flex touch-target items-center gap-2 rounded-full border px-3 text-sm font-medium"
                                       @style([
                                           "border-color: {$member->colour}; background-color: {$member->colour}1a" => $on,
                                       ])
                                       @class([
                                           'border-slate-300 dark:border-slate-700' => ! $on,
                                       ])>
                                    <input type="checkbox" wire:model.live="memberIds" value="{{ $member->id }}" class="sr-only">
                                    <span class="size-3 rounded-full" style="background-color: {{ $member->colour }};"></span>
                                    {{ $member->name }}
                                </label>
                            @endforeach
                        </div>

                        @if ($memberIds === [])
                            <p class="mt-1 text-sm text-slate-400">Nobody selected — it shows as a household event.</p>
                        @endif
                    </fieldset>

                    <div>
                        <label class="block text-sm font-medium" for="ev-location">Where <span class="text-slate-400">(optional)</span></label>
                        <input wire:model="location" id="ev-location" type="text"
                               class="touch-target mt-1 w-full rounded-xl border border-slate-300 px-4 dark:border-slate-700 dark:bg-slate-950">
                    </div>

                    @if ($error)
                        <p class="rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-300">{{ $error }}</p>
                    @endif

                    <div class="flex gap-2 pt-1">
                        <button type="submit" class="touch-target flex-1 rounded-xl bg-blue-600 font-semibold text-white">
                            <span wire:loading.remove wire:target="save">Save</span>
                            <span wire:loading wire:target="save">Saving to iCloud…</span>
                        </button>
                        <button type="button" wire:click="$set('open', false)" class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                        @if ($eventId)
                            <button type="button" wire:click="deleteEvent" wire:confirm="Delete this event from iCloud?"
                                    class="touch-target rounded-xl px-4 font-semibold text-red-600">Delete</button>
                        @endif
                    </div>
                </form>
            @endif
            </div>
        </x-modal>
    @endif
</div>
