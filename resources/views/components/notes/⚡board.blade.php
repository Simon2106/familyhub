<?php

use App\Models\Household;
use App\Models\Member;
use App\Models\Note;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The fridge door, on the wall and in a pocket.
 *
 * One component in two shapes. On the wall it is a row of stickies to be read
 * from across a kitchen and written with the touch keyboard; on a phone it is
 * a list with a text box. What it is not, in either place, is a to-do list:
 * there is nothing to tick, because "back late Tuesday" is not a job and
 * pretending it is one leaves it sitting unticked forever.
 *
 * Notes expire instead. A week by default, because the whole point of a scrap
 * of paper is that somebody eventually throws it away, and nobody ever does.
 */
new class extends Component
{
    /** Drawn for the wall: bigger, fewer, and no editing in place. */
    public bool $onWall = false;

    public string $body = '';

    public ?int $author = null;

    /** Days until it goes. 0 means it stays until somebody takes it down. */
    public int $days = Note::DEFAULT_DAYS;

    public ?int $editing = null;

    public bool $composing = false;

    public ?string $problem = null;

    public function mount(bool $onWall = false): void
    {
        $this->onWall = $onWall;
    }

    public function household(): Household
    {
        return Household::current();
    }

    /**
     * The living notes, newest last so the wall reads left to right.
     *
     * Expired ones are swept as they are read rather than by a scheduled
     * command: a note board is looked at many times a day, and a nightly job
     * to delete six rows is a moving part that can only break.
     *
     * @return Collection<int, Note>
     */
    #[Computed]
    public function notes(): Collection
    {
        $today = $this->household()->todayLocal()->toDateString();

        Note::query()->where('household_id', $this->household()->id)->expired($today)->delete();

        return Note::query()
            ->where('household_id', $this->household()->id)
            ->live($today)
            ->with('member')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->limit($this->onWall ? Note::ON_THE_WALL : 50)
            ->get();
    }

    /** How many are not being shown, so the wall can say so honestly. */
    #[Computed]
    public function hidden(): int
    {
        if (! $this->onWall) {
            return 0;
        }

        $total = Note::query()
            ->where('household_id', $this->household()->id)
            ->live($this->household()->todayLocal()->toDateString())
            ->count();

        return max(0, $total - Note::ON_THE_WALL);
    }

    /** @return Collection<int, Member> */
    #[Computed]
    public function members(): Collection
    {
        return $this->household()->members()->orderBy('sort_order')->orderBy('name')->get();
    }

    public function compose(): void
    {
        $this->reset(['body', 'days', 'editing', 'problem']);

        $this->days = Note::DEFAULT_DAYS;
        $this->composing = true;
    }

    public function edit(int $id): void
    {
        $note = $this->find($id);

        if (! $note) {
            return;
        }

        $this->editing = $note->id;
        $this->body = $note->body;
        $this->author = $note->member_id;
        $this->days = $note->expires_on
            ? max(0, (int) $this->household()->todayLocal()->startOfDay()
                ->diffInDays(CarbonImmutable::parse($note->expires_on)->startOfDay(), false))
            : 0;
        $this->composing = true;
        $this->problem = null;
    }

    public function save(): void
    {
        $body = trim($this->body);
        $this->problem = null;

        if ($body === '') {
            $this->problem = 'Write something first.';

            return;
        }

        $today = $this->household()->todayLocal();
        $days = max(0, min(365, $this->days));

        $attributes = [
            'member_id' => $this->author ?: null,
            'body' => mb_substr($body, 0, 280),
            // 0 means "leave it up": the family takes it down themselves.
            'expires_on' => $days === 0 ? null : $today->addDays($days)->toDateString(),
        ];

        if ($this->editing && ($note = $this->find($this->editing))) {
            $note->forceFill($attributes)->save();
        } else {
            Note::create($attributes + ['household_id' => $this->household()->id]);
        }

        $this->close();
    }

    public function remove(int $id): void
    {
        $this->find($id)?->delete();

        $this->close();
    }

    public function close(): void
    {
        $this->reset(['body', 'editing', 'composing', 'problem']);

        $this->days = Note::DEFAULT_DAYS;

        unset($this->notes, $this->hidden);

        $this->dispatch('notes-changed');
    }

    #[On('notes-changed')]
    public function refresh(): void
    {
        unset($this->notes, $this->hidden);
    }

    /** Scoped to the household, because the id arrives from the browser. */
    protected function find(int $id): ?Note
    {
        return Note::where('household_id', $this->household()->id)->find($id);
    }
}; ?>

<div class="min-h-0">
    @if ($onWall)
        {{-- ------------------------------- WALL ------------------------- --}}
        {{-- A row of stickies. Nothing here scrolls: six is the most a wall
             can carry and still be read from the other side of a kitchen,
             and a seventh hiding off-screen is a note nobody sees. --}}
        <div class="flex items-stretch gap-3">
            @foreach ($this->notes as $note)
                <button type="button" wire:click="edit({{ $note->id }})" wire:key="note-{{ $note->id }}"
                        class="flex min-w-0 flex-1 flex-col rounded-2xl px-4 py-3 text-left"
                        style="background-color: {{ $note->colour() }}1a; border-left: 6px solid {{ $note->colour() }};">
                    <span class="line-clamp-3 text-xl leading-snug font-medium">{{ $note->body }}</span>
                    <span class="mt-auto pt-2 truncate text-sm text-slate-500 dark:text-slate-400">
                        {{ $note->member?->name ?? 'Everyone' }}@if ($note->until($this->household()->todayLocal())) · {{ $note->until($this->household()->todayLocal()) }}@endif
                    </span>
                </button>
            @endforeach

            {{-- The empty board is the add control: a lone + on an otherwise
                 bare strip says nothing about what it makes, and a wall has
                 no hover to explain it with. --}}
            @if ($this->notes->isEmpty())
                <button type="button" wire:click="compose"
                        class="flex min-h-touch flex-1 items-center gap-3 rounded-2xl border-2 border-dashed border-slate-300 px-4 py-3 text-left dark:border-slate-700">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-2xl leading-none text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                          aria-hidden="true">+</span>
                    <span class="min-w-0">
                        <span class="block text-xl font-medium">Add a note</span>
                        <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                            e.g. Gran’s here at 4
                        </span>
                    </span>
                </button>
            @else
                <button type="button" wire:click="compose"
                        class="flex min-h-touch w-40 shrink-0 flex-col items-center justify-center gap-1 rounded-2xl border-2 border-dashed border-slate-300 px-3 py-3 text-slate-500 dark:border-slate-700 dark:text-slate-400">
                    <span class="text-3xl leading-none" aria-hidden="true">+</span>
                    <span class="text-base font-medium">Add a note</span>
                </button>
            @endif
        </div>

        @if ($this->hidden > 0)
            <p class="pt-1 text-xs text-slate-400">and {{ $this->hidden }} more on the phone</p>
        @endif
    @else
        {{-- ------------------------------- PHONE ------------------------ --}}
        <section class="rounded-2xl bg-white p-3 dark:bg-slate-900">
            <div class="flex items-center gap-2">
                <h2 class="flex-1 text-sm font-semibold tracking-wide text-slate-400 uppercase">Notes</h2>
                <button type="button" wire:click="compose"
                        class="flex touch-target items-center gap-1.5 rounded-xl px-3 text-sm font-semibold text-blue-600 dark:text-blue-400">
                    <span class="text-lg leading-none" aria-hidden="true">+</span>
                    Add a note
                </button>
            </div>

            @if ($this->notes->isEmpty())
                {{-- The same offer as the wall, in the same words. --}}
                <button type="button" wire:click="compose"
                        class="mt-1 flex w-full touch-target items-center gap-3 rounded-xl border-2 border-dashed border-slate-300 px-3 py-3 text-left dark:border-slate-700">
                    <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-lg leading-none text-slate-500 dark:bg-slate-800 dark:text-slate-400"
                          aria-hidden="true">+</span>
                    <span class="min-w-0">
                        <span class="block font-medium">Add a note</span>
                        <span class="block truncate text-sm text-slate-500 dark:text-slate-400">
                            e.g. Gran’s here at 4
                        </span>
                    </span>
                </button>
            @else
                <ul class="mt-1 space-y-2">
                    @foreach ($this->notes as $note)
                        <li wire:key="pnote-{{ $note->id }}">
                            <button type="button" wire:click="edit({{ $note->id }})"
                                    class="flex w-full items-start gap-3 rounded-xl px-3 py-2 text-left"
                                    style="background-color: {{ $note->colour() }}14; border-left: 4px solid {{ $note->colour() }};">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium">{{ $note->body }}</span>
                                    <span class="block truncate text-xs text-slate-500 dark:text-slate-400">
                                        {{ $note->member?->name ?? 'Everyone' }}@if ($note->until($this->household()->todayLocal())) · {{ $note->until($this->household()->todayLocal()) }}@endif
                                    </span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- ------------------------------ the dialog ------------------------ --}}
    @if ($composing)
        <x-modal dismiss="close">
            <x-slot:header>
                <h2 class="text-lg font-bold">{{ $editing ? 'Change the note' : 'A note for the fridge door' }}</h2>
            </x-slot:header>

            <div class="space-y-4">
                <label class="block">
                    <span class="sr-only">The note</span>
                    <textarea wire:model="body" rows="3" maxlength="280" autofocus
                              placeholder="Back late Tuesday"
                              class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-lg dark:border-slate-700 dark:bg-slate-800"></textarea>
                </label>

                @if ($problem)
                    <p class="text-sm font-medium text-rose-600 dark:text-rose-400">{{ $problem }}</p>
                @endif

                <div>
                    <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">Whose is it?</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        <button type="button" wire:click="$set('author', null)"
                                class="touch-target rounded-xl px-4 text-sm font-semibold {{ $author === null ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-800' }}">
                            Everyone
                        </button>
                        @foreach ($this->members as $member)
                            <button type="button" wire:click="$set('author', {{ $member->id }})" wire:key="who-{{ $member->id }}"
                                    class="touch-target rounded-xl px-4 text-sm font-semibold {{ $author === $member->id ? 'text-white' : 'bg-slate-100 dark:bg-slate-800' }}"
                                    @style(['background-color: '.$member->colour => $author === $member->id])>
                                {{ $member->name }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">Take it down</span>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach ([1 => 'Tomorrow', 3 => 'In 3 days', 7 => 'In a week', 0 => 'Leave it up'] as $value => $label)
                            <button type="button" wire:click="$set('days', {{ $value }})" wire:key="days-{{ $value }}"
                                    class="touch-target rounded-xl px-4 text-sm font-semibold {{ $days === $value ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <x-slot:footer>
                <div class="flex gap-2">
                    @if ($editing)
                        <button type="button" wire:click="remove({{ $editing }})"
                                class="touch-target rounded-xl px-4 font-semibold text-rose-600 dark:text-rose-400">
                            Take it down
                        </button>
                    @endif
                    <button type="button" wire:click="close"
                            class="touch-target ml-auto rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                    <button type="button" wire:click="save"
                            class="touch-target rounded-xl bg-blue-600 px-6 font-semibold text-white">
                        {{ $editing ? 'Save' : 'Stick it up' }}
                    </button>
                </div>
            </x-slot:footer>
        </x-modal>
    @endif
</div>
