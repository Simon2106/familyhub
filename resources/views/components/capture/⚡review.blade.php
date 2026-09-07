<?php

use App\Models\Calendar;
use App\Models\Capture;
use App\Models\CaptureItem;
use App\Models\CalendarAccount;
use App\Models\Household;
use App\Services\Capture\ItemAcceptor;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The review inbox: everything a capture found, awaiting a decision.
 *
 * Nothing here has touched a calendar. Accepting writes through to iCloud;
 * rejecting just closes the item.
 */
new class extends Component
{
    /** Phones can edit an item before accepting; the wall accepts or rejects. */
    public bool $editable = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $date = '';

    public string $time = '';

    public bool $allDay = false;

    public string $location = '';

    public string $memberId = '';

    public string $calendarId = '';

    public ?string $error = null;

    public function mount(bool $editable = false): void
    {
        $this->editable = $editable;
    }

    #[Computed]
    public function captures(): Collection
    {
        return Capture::query()
            ->where('household_id', Household::current()->id)
            ->whereIn('status', ['reviewing', 'processing', 'pending', 'failed'])
            ->with([
                'items' => fn ($q) => $q->where('status', 'pending'),
                'items.member',
                'items.source',
                // Counted here rather than per row: the card renders one line
                // per source and a query each would be an N+1.
                'sources' => fn ($q) => $q->withCount('items'),
            ])
            ->orderByDesc('created_at')
            ->get()
            // A capture whose items have all been dealt with drops out, but one
            // still working or failed stays so its state is visible.
            ->reject(fn (Capture $c) => $c->status === 'reviewing' && $c->items->isEmpty());
    }

    #[Computed]
    public function pendingCount(): int
    {
        return CaptureItem::query()
            ->whereHas('capture', fn ($q) => $q->where('household_id', Household::current()->id))
            ->pending()
            ->count();
    }

    #[Computed]
    public function members(): Collection
    {
        return Household::current()->members;
    }

    #[Computed]
    public function calendars(): Collection
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

    public function accept(int $itemId): void
    {
        $this->error = null;
        $item = $this->findItem($itemId);

        try {
            app(ItemAcceptor::class)->accept($item);
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->afterReview($item->type === 'event' ? 'Added to the calendar.' : 'Added to the list.');
    }

    public function reject(int $itemId): void
    {
        app(ItemAcceptor::class)->reject($this->findItem($itemId));

        $this->afterReview('Dismissed.');
    }

    /**
     * Accept everything the model was confident about, leaving the rest.
     *
     * Stops at the first failure so a missing calendar does not produce one
     * error per item.
     */
    public function acceptConfident(int $captureId): void
    {
        $this->error = null;
        $acceptor = app(ItemAcceptor::class);
        $accepted = 0;

        $items = $this->findCapture($captureId)->items()->pending()->highConfidence()->get();

        foreach ($items as $item) {
            try {
                $acceptor->accept($item);
                $accepted++;
            } catch (RuntimeException $e) {
                $this->error = $e->getMessage();
                break;
            }
        }

        $this->afterReview($accepted === 0
            ? 'Nothing was confident enough to accept on its own.'
            : "Accepted {$accepted} item".($accepted === 1 ? '' : 's').'.');
    }

    public function dismissCapture(int $captureId): void
    {
        $capture = $this->findCapture($captureId);

        $capture->items()->pending()->update(['status' => 'rejected', 'reviewed_at' => now()]);
        $capture->forceFill(['status' => 'done'])->save();

        $this->afterReview('Dismissed.');
    }

    /**
     * The correction in the other direction.
     *
     * A shared recipe that came in through the calendar side of the share
     * target belongs in the recipe box, and re-sharing it from Instagram to
     * get it there would be absurd.
     */
    public function saveAsMealIdea(int $captureId): void
    {
        $capture = $this->findCapture($captureId);

        $link = $capture->raw_payload && str_starts_with((string) $capture->raw_payload, 'http')
            ? $capture->raw_payload
            : null;

        $link
            ? app(\App\Services\Recipes\RecipeIntake::class)->fromUrl($link, $capture->body_text)
            : app(\App\Services\Recipes\RecipeIntake::class)->fromText(
                trim($capture->subject."\n\n".$capture->body_text)
            );

        $capture->forceFill(['status' => 'done'])->save();

        $this->afterReview('Saved to the recipe box.');
    }

    public function retry(int $captureId): void
    {
        $capture = $this->findCapture($captureId);

        // touch() as well as the status: a stalled capture is judged on
        // updated_at, and a retry that left it stale would still look stuck.
        $capture->forceFill(['status' => 'pending', 'error' => null])->save();
        $capture->touch();

        \App\Jobs\ProcessCaptureJob::dispatch($capture);

        $this->afterReview('Trying again…');
    }

    public function edit(int $itemId): void
    {
        if (! $this->editable) {
            return;
        }

        $item = $this->findItem($itemId);
        $tz = Household::current()->displayTimezone();

        $this->editingId = $item->id;
        $this->title = $item->title;
        $this->date = $item->start_at?->timezone($tz)->toDateString() ?? '';
        $this->time = $item->all_day ? '' : ($item->start_at?->timezone($tz)->format('H:i') ?? '');
        $this->allDay = $item->all_day;
        $this->location = (string) $item->location;
        $this->memberId = (string) ($item->member_id ?? '');
        $this->calendarId = (string) ($item->calendar_id ?? $this->calendars()->first()?->id ?? '');
        $this->error = null;
    }

    public function saveEdit(): void
    {
        $this->validate([
            'title' => 'required|string|max:200',
            'date' => 'nullable|date',
            'time' => 'nullable|date_format:H:i',
            'location' => 'nullable|string|max:200',
        ]);

        $item = $this->findItem((int) $this->editingId);
        $tz = Household::current()->displayTimezone();

        $start = null;

        if ($this->date !== '') {
            $start = $this->allDay || $this->time === ''
                ? \Carbon\CarbonImmutable::parse($this->date, $tz)->startOfDay()
                : \Carbon\CarbonImmutable::parse("{$this->date} {$this->time}", $tz);
        }

        $item->update([
            'title' => trim($this->title),
            'start_at' => $start,
            // A start that moved invalidates whatever end the model guessed.
            'end_at' => $start === null ? null : ($this->allDay ? $start->endOfDay() : $start->addHour()),
            'all_day' => $this->allDay || ($start !== null && $this->time === ''),
            'location' => $this->location ?: null,
            'member_id' => $this->memberId !== '' ? (int) $this->memberId : null,
            'calendar_id' => $this->calendarId !== '' ? (int) $this->calendarId : null,
        ]);

        $this->editingId = null;
        unset($this->captures);
    }

    protected function afterReview(string $message): void
    {
        $this->editingId = null;

        unset($this->captures, $this->pendingCount);

        $this->dispatch('captures-changed');
        $this->dispatch('saved', message: $this->error ?? $message);
    }

    protected function findItem(int $id): CaptureItem
    {
        return CaptureItem::query()
            ->whereHas('capture', fn ($q) => $q->where('household_id', Household::current()->id))
            ->findOrFail($id);
    }

    protected function findCapture(int $id): Capture
    {
        return Capture::where('household_id', Household::current()->id)->findOrFail($id);
    }

    #[On('captures-changed')]
    public function refreshCaptures(): void
    {
        unset($this->captures, $this->pendingCount);
    }
}; ?>

@php $tz = Household::current()->displayTimezone(); @endphp

<div class="pane-scroll h-full min-h-0" wire:poll.30s>
    @if ($error)
        <p class="mb-3 rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-300">{{ $error }}</p>
    @endif

    @forelse ($this->captures as $capture)
        <section class="mb-4 rounded-2xl bg-white p-4 last:mb-0 dark:bg-slate-900" wire:key="capture-{{ $capture->id }}">
            <header class="flex items-start gap-3">
                <div class="min-w-0 flex-1">
                    <h2 class="truncate font-semibold">{{ $capture->label() }}</h2>
                    <p class="truncate text-sm text-slate-500 dark:text-slate-400">
                        {{ ucfirst($capture->source) }}@if ($capture->sender) · {{ $capture->sender }} @endif
                        · {{ $capture->created_at->diffForHumans() }}
                    </p>
                </div>

                @if ($capture->items->isNotEmpty())
                    <span class="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        {{ $capture->items->count() }}
                    </span>
                @endif
            </header>

            @if ($capture->summary)
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $capture->summary }}</p>
            @endif

            @if ($capture->sources->isNotEmpty())
                {{-- What each document was, in the model's words. Worth being
                     able to see when an item looks wrong: it usually says
                     whether the document was misread or simply says that. --}}
                <div x-data="{ open: false }" class="mt-2">
                    <button type="button" x-on:click="open = ! open"
                            class="flex touch-target items-center gap-1.5 rounded-lg text-sm font-semibold text-blue-600 dark:text-blue-400">
                        <svg class="size-4 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="m9 6 6 6-6 6" />
                        </svg>
                        <span x-text="open ? 'Hide sources' : 'Show source'"></span>
                        <span class="font-normal text-slate-400">({{ $capture->sources->count() }})</span>
                    </button>

                    <ul x-show="open" x-cloak x-collapse class="mt-1 space-y-2">
                        @foreach ($capture->sources as $source)
                            <li class="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/60" wire:key="source-{{ $source->id }}">
                                <p class="flex items-center gap-2 font-semibold">
                                    @if ($source->isAttachment())
                                        <svg class="size-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M14 3v5h5M8 3h7l5 5v11a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
                                        </svg>
                                    @else
                                        <svg class="size-4 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                            <path d="M4 5h16v14H4z M4 6l8 6 8-6" />
                                        </svg>
                                    @endif
                                    <span class="min-w-0 truncate">{{ $source->label }}</span>
                                    <span class="ml-auto shrink-0 text-xs font-normal text-slate-400">
                                        {{ $source->items_count }} found
                                    </span>
                                </p>
                                <p class="mt-1 text-slate-500 dark:text-slate-400">
                                    {{ $source->summary ?: 'Nothing recorded for this one.' }}
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($capture->status === 'failed' || $capture->seemsStalled())
                {{-- A stalled capture gets the same treatment as a failed one:
                     a worker killed mid-job leaves nothing to move it on, and
                     spinning forever tells the household nothing. --}}
                <p class="mt-3 rounded-xl bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-300">
                    {{ $capture->status === 'failed'
                        ? ($capture->error ?: 'Something went wrong reading this.')
                        : 'This stopped part-way through and did not finish.' }}
                </p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="button" wire:click="retry({{ $capture->id }})"
                            class="touch-target rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">
                        <span wire:loading.remove wire:target="retry({{ $capture->id }})">Try again</span>
                        <span wire:loading wire:target="retry({{ $capture->id }})">Queueing…</span>
                    </button>
                    <button type="button" wire:click="dismissCapture({{ $capture->id }})"
                            class="touch-target rounded-xl px-4 text-sm font-semibold text-slate-500">Dismiss</button>
                </div>
            @elseif (in_array($capture->status, ['pending', 'processing'], true))
                <p class="mt-3 flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
                    <svg class="size-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v3a5 5 0 0 0-5 5z"/>
                    </svg>
                    Reading it…
                </p>
            @endif

            @if ($capture->items->isNotEmpty())
                <ul class="mt-3 space-y-2">
                    @foreach ($capture->items as $item)
                        <li wire:key="item-{{ $item->id }}"
                            class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">

                            @if ($editingId === $item->id)
                                <form wire:submit="saveEdit" class="space-y-2">
                                    <input wire:model="title" type="text" aria-label="Title"
                                           class="touch-target w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600 dark:bg-slate-950">
                                    @error('title') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

                                    <div class="flex gap-2">
                                        <input wire:model="date" type="date" aria-label="Date"
                                               class="touch-target min-w-0 flex-1 rounded-xl border border-slate-300 px-3 dark:border-slate-600 dark:bg-slate-950">
                                        @unless ($allDay)
                                            <input wire:model="time" type="time" aria-label="Time"
                                                   class="touch-target min-w-0 flex-1 rounded-xl border border-slate-300 px-3 dark:border-slate-600 dark:bg-slate-950">
                                        @endunless
                                    </div>

                                    <label class="flex touch-target items-center gap-2 text-sm">
                                        <input wire:model.live="allDay" type="checkbox" class="size-5 rounded">
                                        All day
                                    </label>

                                    <input wire:model="location" type="text" placeholder="Where" aria-label="Location"
                                           class="touch-target w-full rounded-xl border border-slate-300 px-3 dark:border-slate-600 dark:bg-slate-950">

                                    <div class="flex gap-2">
                                        <select wire:model="memberId" aria-label="Who for"
                                                class="touch-target min-w-0 flex-1 rounded-xl border border-slate-300 px-2 text-sm dark:border-slate-600 dark:bg-slate-950">
                                            <option value="">Nobody in particular</option>
                                            @foreach ($this->members as $member)
                                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                                            @endforeach
                                        </select>

                                        @if ($item->type === 'event')
                                            <select wire:model="calendarId" aria-label="Calendar"
                                                    class="touch-target min-w-0 flex-1 rounded-xl border border-slate-300 px-2 text-sm dark:border-slate-600 dark:bg-slate-950">
                                                @foreach ($this->calendars as $calendar)
                                                    <option value="{{ $calendar->id }}">{{ $calendar->name }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </div>

                                    <div class="flex gap-2">
                                        <button type="submit" class="touch-target flex-1 rounded-xl bg-slate-900 text-sm font-semibold text-white dark:bg-white dark:text-slate-900">Save</button>
                                        <button type="button" wire:click="$set('editingId', null)"
                                                class="touch-target rounded-xl px-4 text-sm font-semibold text-slate-500">Cancel</button>
                                    </div>
                                </form>
                            @else
                                <div class="flex items-start gap-2">
                                    <span class="mt-0.5 shrink-0 rounded px-1.5 py-0.5 text-[0.65rem] font-bold tracking-wide uppercase
                                        {{ $item->type === 'event' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">
                                        {{ $item->type }}
                                    </span>
                                    <p class="min-w-0 flex-1 font-medium">{{ $item->title }}</p>
                                </div>

                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    @if ($item->start_at)
                                        {{ $item->start_at->timezone($tz)->format($item->all_day ? 'D j M Y' : 'D j M Y, H:i') }}
                                    @else
                                        <span class="text-amber-700 dark:text-amber-400">No date found</span>
                                    @endif
                                    @if ($item->location) · {{ $item->location }} @endif
                                </p>

                                @if ($item->member_hint)
                                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">Mentions: {{ $item->member_hint }}</p>
                                @endif

                                {{-- A deadline on its own is hard to judge; what
                                     it is in aid of is the context that makes it
                                     accept-or-reject. --}}
                                @if ($item->for_event_title)
                                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                                        For: {{ $item->for_event_title }}
                                    </p>
                                @endif

                                @if ($item->source)
                                    {{-- Which document this came from: an item
                                         read out of the PDF carries different
                                         weight from one guessed off a cover note. --}}
                                    <p class="mt-0.5 truncate text-xs text-slate-400">
                                        from {{ $item->source->shortLabel() }}
                                    </p>
                                @endif

                                @if ($item->notes)
                                    <p class="mt-1 text-sm text-slate-400">{{ $item->notes }}</p>
                                @endif

                                <div class="mt-2 flex items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold
                                        {{ $item->isHighConfidence()
                                            ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300'
                                            : 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' }}">
                                        {{ $item->confidenceLabel() }}
                                    </span>

                                    <span class="flex-1"></span>

                                    @if ($editable)
                                        <button type="button" wire:click="edit({{ $item->id }})"
                                                class="touch-target rounded-xl px-3 text-sm font-semibold text-slate-500">Edit</button>
                                    @endif
                                    <button type="button" wire:click="reject({{ $item->id }})"
                                            class="touch-target rounded-xl px-3 text-sm font-semibold text-slate-500">No</button>
                                    <button type="button" wire:click="accept({{ $item->id }})"
                                            class="touch-target rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">Add</button>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($capture->items->where('confidence', '>=', \App\Models\CaptureItem::HIGH_CONFIDENCE)->isNotEmpty())
                        <button type="button" wire:click="acceptConfident({{ $capture->id }})"
                                class="touch-target rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white">
                            Accept all confident
                        </button>
                    @endif
                    {{-- A shared reel or recipe page that came in on the
                         calendar side of the share target. Moving it beats
                         asking anyone to share it again. --}}
                    <button type="button" wire:click="saveAsMealIdea({{ $capture->id }})"
                            class="touch-target rounded-xl px-4 text-sm font-semibold text-slate-500">
                        It's a recipe
                    </button>
                    <button type="button" wire:click="dismissCapture({{ $capture->id }})"
                            wire:confirm="Dismiss everything from this one?"
                            class="touch-target ml-auto rounded-xl px-4 text-sm font-semibold text-slate-500">Dismiss the rest</button>
                </div>
            @endif
        </section>
    @empty
        <div class="grid h-full place-items-center">
            <div class="text-center">
                <p class="text-slate-400">Nothing waiting to be reviewed.</p>
                <p class="mt-1 text-sm text-slate-400 dark:text-slate-600">
                    Forward a school email, or add a photo from your phone.
                </p>
            </div>
        </div>
    @endforelse
</div>
