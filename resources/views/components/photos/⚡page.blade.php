<?php

use App\Models\Household;
use App\Models\Photo;
use App\Services\PhotoLibrary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The photographs the wall shows, and what to say about them.
 *
 * Hiding rather than deleting is the default action: a photograph somebody has
 * said no to is not one the next sync should quietly bring back, and "not that
 * one" is usually about the wall rather than about the picture.
 */
new #[Layout('layouts::app')] class extends Component
{
    use WithFileUploads;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public $uploads = [];

    public ?int $editingId = null;

    public string $caption = '';

    public bool $showHidden = false;

    public ?string $problem = null;

    /** The photograph open full-screen, by id. */
    public ?int $viewing = null;

    /** The last one hidden, so it can be put back without hunting for it. */
    public ?int $undoHide = null;

    public bool $syncing = false;

    /**
     * Drawn for the wall: bigger tiles, no upload, and no way back.
     *
     * The same component either way rather than two — the viewer, the
     * favouriting and the long-press are the same behaviour on both, and two
     * copies of it would drift.
     */
    public bool $onWall = false;

    public function mount(bool $onWall = false): void
    {
        $this->onWall = $onWall;
    }

    public function household(): Household
    {
        return Household::current();
    }

    /** @return Collection<int, Photo> */
    #[Computed]
    public function photos(): Collection
    {
        app(PhotoLibrary::class)->adoptLooseFiles($this->household());

        return Photo::query()
            ->where('household_id', $this->household()->id)
            ->when(! $this->showHidden, fn ($q) => $q->showable())
            ->latest('taken_at')
            ->latest('id')
            ->limit(300)
            ->get();
    }

    #[Computed]
    public function hiddenCount(): int
    {
        return Photo::where('household_id', $this->household()->id)->where('is_hidden', true)->count();
    }

    /* ------------------------------ the album --------------------------- */

    /**
     * What the album is called, when Apple has told us.
     *
     * Falls back to the words rather than to a blank: "the shared album" is
     * a true and useful thing to call something with no title.
     */
    #[Computed]
    public function albumName(): string
    {
        return $this->household()->photoAlbumName() ?? 'the shared album';
    }

    #[Computed]
    public function syncedAt(): ?string
    {
        $at = $this->household()->photosSyncedAt();

        return $at?->diffForHumans(['parts' => 1]);
    }

    #[Computed]
    public function syncError(): ?string
    {
        return $this->household()->photoSyncError();
    }

    /**
     * Read the album now.
     *
     * Run inline rather than queued: somebody has tapped a button and is
     * waiting to see whether it worked, and the answer is more useful than
     * the half-second it costs.
     */
    public function syncNow(): void
    {
        $this->syncing = true;
        $this->problem = null;

        if (blank($this->household()->photoAlbumUrl())) {
            $this->problem = 'No shared album is set up yet — add the link in Settings → Wall display.';
            $this->syncing = false;

            return;
        }

        try {
            Artisan::call('familyhub:sync-photos');
        } catch (\Throwable $e) {
            report($e);

            $this->problem = 'That did not work: '.$e->getMessage();
        }

        $this->syncing = false;

        unset($this->photos, $this->hiddenCount, $this->syncedAt, $this->albumName, $this->syncError);

        $this->dispatch('saved', message: 'Album read.');
    }

    /* ------------------------------ one photo --------------------------- */

    public function openPhoto(int $id): void
    {
        $this->viewing = $this->find($id)->id;

        $this->forgetViewer();
    }

    public function closeViewer(): void
    {
        $this->viewing = null;

        $this->forgetViewer();
    }

    /**
     * Where the viewer is in the grid must be worked out again.
     *
     * A computed is memoised for the whole request, and step() asks for the
     * index before it changes which photograph is showing — so without this
     * the render reuses the position the viewer was at a moment ago and the
     * counter never moves.
     */
    protected function forgetViewer(): void
    {
        unset($this->viewerIndex);
    }

    /** The one showing, and the ones either side of it, for swiping. */
    #[Computed]
    public function viewerIndex(): ?int
    {
        if (! $this->viewing) {
            return null;
        }

        $at = $this->photos->search(fn (Photo $photo) => $photo->id === $this->viewing);

        return $at === false ? null : $at;
    }

    public function step(int $by): void
    {
        $at = $this->viewerIndex;

        if ($at === null) {
            return;
        }

        $next = $this->photos->get($at + $by);

        if ($next) {
            $this->viewing = $next->id;

            $this->forgetViewer();
        }
    }

    public function toggleFavourite(int $id): void
    {
        $photo = $this->find($id);

        $photo->update(['is_favourite' => ! $photo->is_favourite]);

        unset($this->photos);
    }

    /**
     * Hidden, with a way back.
     *
     * A long press is easy to do by accident on a screen full of pictures,
     * and a photograph that vanished with no way to say "no, that one" would
     * mean going to find it among the hidden ones.
     */
    public function hide(int $id): void
    {
        $photo = $this->find($id);

        if ($photo->is_hidden) {
            return;
        }

        $photo->update(['is_hidden' => true]);

        $this->undoHide = $photo->id;
        $this->viewing = null;

        unset($this->photos, $this->hiddenCount);

        $this->forgetViewer();
    }

    public function undoLastHide(): void
    {
        if ($this->undoHide) {
            $this->find($this->undoHide)->update(['is_hidden' => false]);
        }

        $this->undoHide = null;

        unset($this->photos, $this->hiddenCount);
    }

    public function dismissUndo(): void
    {
        $this->undoHide = null;
    }

    public function save(): void
    {
        $this->problem = null;

        $this->validate([
            'uploads.*' => 'image|max:12288',
        ], [], ['uploads.*' => 'photograph']);

        $disk = (string) config('familyhub.photos.disk');
        $folder = trim((string) config('familyhub.photos.path'), '/');

        foreach ($this->uploads as $upload) {
            $path = $upload->storeAs(
                $folder,
                Str::uuid().'.'.($upload->getClientOriginalExtension() ?: 'jpg'),
                $disk,
            );

            Photo::create([
                'household_id' => $this->household()->id,
                'source' => 'upload',
                'disk' => $disk,
                'path' => $path,
                // Uploaded now, so that is when the wall treats it as being
                // from — which is what makes "favour recent" work for the
                // photographs somebody has just chosen.
                'taken_at' => now(),
            ]);
        }

        $this->reset('uploads');
        unset($this->photos, $this->hiddenCount);

        $this->dispatch('saved', message: 'Added.');
    }

    public function editCaption(int $id): void
    {
        $photo = $this->find($id);

        $this->editingId = $this->editingId === $id ? null : $id;
        $this->caption = (string) $photo->caption;
    }

    public function saveCaption(): void
    {
        if (! $this->editingId) {
            return;
        }

        $this->find($this->editingId)->update(['caption' => trim($this->caption) ?: null]);

        $this->editingId = null;
        unset($this->photos);

        $this->dispatch('saved', message: 'Caption saved.');
    }

    public function toggleHidden(int $id): void
    {
        $photo = $this->find($id);

        $photo->update(['is_hidden' => ! $photo->is_hidden]);

        unset($this->photos, $this->hiddenCount);
    }

    public function forget(int $id): void
    {
        $this->find($id)->forget();

        unset($this->photos, $this->hiddenCount);
    }

    protected function find(int $id): Photo
    {
        return Photo::where('household_id', $this->household()->id)->findOrFail($id);
    }
}; ?>

<div class="{{ $onWall ? 'flex h-full min-h-0 flex-col' : 'app-shell flex flex-col' }}">
    @unless ($onWall)
        <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
            <a href="{{ route('app') }}" wire:navigate
               class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
                <x-icon name="chevron-left" class="size-6" />
            </a>
            <h1 class="flex-1 text-2xl font-bold">Photos</h1>
        </header>
    @endunless

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    {{-- Hidden something by accident: put it back without going to find it. --}}
    @if ($undoHide)
        <div class="mx-4 mb-2 flex shrink-0 items-center gap-3 rounded-xl bg-slate-900 px-4 py-3 text-white dark:bg-white dark:text-slate-900">
            <span class="min-w-0 flex-1 text-sm font-medium">Hidden from the wall.</span>
            <button type="button" wire:click="undoLastHide"
                    class="touch-target rounded-lg px-3 text-sm font-bold text-blue-300 dark:text-blue-600">Undo</button>
            <button type="button" wire:click="dismissUndo"
                    class="touch-target rounded-lg px-2 text-sm opacity-60" aria-label="Dismiss">&times;</button>
        </div>
    @endif

    <div class="pane-scroll min-h-0 flex-1 px-4 pb-8">
        {{-- Where the photographs come from, and when they last came. --}}
        <section class="rounded-2xl bg-white p-3 dark:bg-slate-900">
            <div class="flex flex-wrap items-center gap-2">
                <div class="min-w-0 flex-1">
                    <p class="truncate font-semibold">{{ $this->albumName }}</p>
                    <p class="truncate text-sm text-slate-500 dark:text-slate-400">
                        @if ($this->syncError)
                            <span class="font-medium text-rose-600 dark:text-rose-400">{{ $this->syncError }}</span>
                        @elseif ($this->syncedAt)
                            Last read {{ $this->syncedAt }} · read hourly
                        @else
                            Not read yet
                        @endif
                    </p>
                </div>

                <button type="button" wire:click="syncNow" wire:loading.attr="disabled" wire:target="syncNow"
                        class="touch-target rounded-xl bg-slate-100 px-4 text-sm font-semibold disabled:opacity-50 dark:bg-slate-800">
                    <span wire:loading.remove wire:target="syncNow">Sync now</span>
                    <span wire:loading wire:target="syncNow">Reading…</span>
                </button>
            </div>

            @if ($problem)
                <p class="mt-2 text-sm font-medium text-rose-600 dark:text-rose-400">{{ $problem }}</p>
            @endif
        </section>

        {{-- Uploading is a phone job: it is where the photographs are, and a
             kiosk has no file picker worth the name. --}}
        @unless ($onWall)
        <form wire:submit="save" class="mt-3 rounded-2xl bg-white p-3 lg:hidden dark:bg-slate-900">
            <label class="block">
                <span class="block text-sm font-medium">Add photographs</span>
                <input wire:model="uploads" type="file" accept="image/*" multiple
                       class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-base dark:border-slate-600 dark:bg-slate-950">
            </label>
            @error('uploads.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <button type="submit" class="mt-2 touch-target w-full rounded-xl bg-blue-600 font-semibold text-white">
                <span wire:loading.remove wire:target="save,uploads">Upload</span>
                <span wire:loading wire:target="save,uploads">Uploading…</span>
            </button>
        </form>
        @endunless

        @if ($this->hiddenCount > 0)
            <label class="mt-3 flex touch-target items-center gap-2">
                <input wire:model.live="showHidden" type="checkbox" class="size-5 rounded">
                <span class="text-sm font-medium">Show the {{ $this->hiddenCount }} hidden</span>
            </label>
        @endif

        {{-- The gesture lives on the grid rather than on each tile.
             Hiding a photograph removes its tile, and a tile removed between
             pointerdown and pointerup takes its Alpine state with it — the
             release then lands on whichever tile slid into its place, with a
             fresh `held` of false, and opens that one instead. The grid is
             still here either way. --}}
        <div class="mt-3 grid gap-3 {{ $onWall ? 'grid-cols-6' : 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6' }}"
             x-data="{
                 held: false,
                 timer: null,
                 press(id) {
                     this.held = false;
                     clearTimeout(this.timer);
                     this.timer = setTimeout(() => { this.held = true; $wire.hide(id) }, 550);
                 },
                 release(id) {
                     clearTimeout(this.timer);
                     if (! this.held) $wire.openPhoto(id);
                     this.held = false;
                 },
                 cancel() { clearTimeout(this.timer); this.held = true; },
             }">
            @forelse ($this->photos as $photo)
                <div class="group relative overflow-hidden rounded-2xl bg-white dark:bg-slate-900"
                     wire:key="photo-{{ $photo->id }}">
                    {{-- Tap opens it; press and hold takes it off the wall.
                         The same gesture the notes board uses, and the same
                         reason: Alpine decides which it was rather than
                         letting both fire. --}}
                    <button type="button"
                            x-on:pointerdown="press({{ $photo->id }})"
                            x-on:pointerup="release({{ $photo->id }})"
                            x-on:pointercancel="cancel()"
                            x-on:pointerleave="cancel()"
                            class="block w-full"
                            aria-label="{{ $photo->caption ?: 'Photograph' }} — tap to open, press and hold to hide">
                        <img src="{{ $photo->url() }}" alt="{{ $photo->caption }}" loading="lazy"
                             class="aspect-square w-full object-cover {{ $photo->is_hidden ? 'opacity-30' : '' }}">
                    </button>

                    {{-- The heart sits on the picture: it is about the
                         picture, and the row underneath is about the words. --}}
                    <button type="button" wire:click="toggleFavourite({{ $photo->id }})"
                            class="absolute top-1.5 right-1.5 grid size-11 place-items-center rounded-full bg-black/35 text-white backdrop-blur-sm"
                            aria-label="{{ $photo->is_favourite ? 'Not a favourite' : 'Make a favourite' }}">
                        <svg class="size-6" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"
                             fill="{{ $photo->is_favourite ? 'currentColor' : 'none' }}" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 20s-7-4.5-7-9.2A3.8 3.8 0 0 1 12 8a3.8 3.8 0 0 1 7 2.8C19 15.5 12 20 12 20z" />
                        </svg>
                    </button>

                    @if ($photo->is_hidden)
                        <span class="absolute top-1.5 left-1.5 rounded-lg bg-black/55 px-2 py-0.5 text-xs font-semibold text-white">
                            Hidden
                        </span>
                    @endif

                    <div class="p-2">
                        @if ($editingId === $photo->id)
                            <input wire:model="caption" wire:keydown.enter="saveCaption" type="text"
                                   placeholder="A word about it"
                                   class="w-full rounded-lg border border-slate-300 px-2 py-1 text-sm dark:border-slate-600 dark:bg-slate-950">
                            <button type="button" wire:click="saveCaption"
                                    class="mt-1 touch-target w-full rounded-lg bg-blue-600 text-sm font-semibold text-white">Save</button>
                        @else
                            <button type="button" wire:click="editCaption({{ $photo->id }})"
                                    class="flex touch-target w-full items-center truncate text-left text-sm {{ $photo->caption ? '' : 'text-slate-400' }}">
                                {{ $photo->caption ?: 'Add a caption' }}
                            </button>
                        @endif

                        <div class="mt-1 flex items-center gap-1">
                            <button type="button" wire:click="toggleHidden({{ $photo->id }})"
                                    class="touch-target flex-1 rounded-lg text-xs font-semibold text-slate-500">
                                {{ $photo->is_hidden ? 'Show again' : 'Hide' }}
                            </button>
                            <button type="button" wire:click="forget({{ $photo->id }})"
                                    wire:confirm="Delete this photograph for good?"
                                    class="touch-target rounded-lg px-2 text-xs font-semibold text-red-600">Delete</button>
                        </div>
                    </div>
                </div>
            @empty
                {{-- The empty state's job is to say where photographs come
                     from, because the answer is somewhere else entirely. --}}
                <div class="col-span-full rounded-2xl border-2 border-dashed border-slate-200 p-8 text-center dark:border-slate-700">
                    <p class="text-lg font-semibold">No photographs yet</p>
                    <p class="mx-auto mt-2 max-w-md text-sm text-slate-500 dark:text-slate-400">
                        The wall shows photographs from an iCloud shared album. Make one on your phone,
                        copy its public link, and paste it into <strong>Settings → Wall display →
                        Photo album</strong>. It is read hourly from then on.
                    </p>
                    <a href="{{ route('admin') }}" wire:navigate
                       class="mt-4 inline-grid touch-target place-items-center rounded-xl bg-blue-600 px-5 font-semibold text-white">
                        Open Settings
                    </a>
                    @unless ($onWall)
                        <p class="mt-3 text-sm text-slate-400 lg:hidden">Or upload a few from this phone, above.</p>
                    @endunless
                </div>
            @endforelse
        </div>
    </div>

    {{-- ---------------------------- the viewer -------------------------- --}}
    @php $open = $this->viewing ? $this->photos->firstWhere('id', $this->viewing) : null; @endphp

    @if ($open)
        <div class="fixed inset-0 z-50 flex flex-col bg-black"
             x-data="{ from: null }"
             x-on:keydown.escape.window="$wire.closeViewer()"
             x-on:keydown.left.window="$wire.step(-1)"
             x-on:keydown.right.window="$wire.step(1)"
             role="dialog" aria-modal="true" aria-label="{{ $open->caption ?: 'Photograph' }}">

            <div class="flex shrink-0 items-center gap-2 p-4">
                <button type="button" wire:click="toggleFavourite({{ $open->id }})"
                        class="grid size-14 shrink-0 place-items-center rounded-full bg-white/10 text-white"
                        aria-label="{{ $open->is_favourite ? 'Not a favourite' : 'Make a favourite' }}">
                    <svg class="size-7" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"
                         fill="{{ $open->is_favourite ? 'currentColor' : 'none' }}" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M12 20s-7-4.5-7-9.2A3.8 3.8 0 0 1 12 8a3.8 3.8 0 0 1 7 2.8C19 15.5 12 20 12 20z" />
                    </svg>
                </button>

                <p class="min-w-0 flex-1 truncate text-lg text-white/80">{{ $open->caption }}</p>

                <button type="button" wire:click="hide({{ $open->id }})"
                        class="touch-target rounded-xl px-4 font-semibold text-white/80">Hide</button>

                <button type="button" wire:click="closeViewer"
                        class="grid size-14 shrink-0 place-items-center rounded-full bg-white/10 text-3xl leading-none text-white"
                        aria-label="Close">&times;</button>
            </div>

            {{-- Swipe between them. A horizontal drag of any length that a
                 finger would call deliberate; anything shorter is a tap that
                 wobbled. --}}
            <div class="relative min-h-0 flex-1"
                 x-on:touchstart="from = $event.changedTouches[0].clientX"
                 x-on:touchend="
                     const dx = $event.changedTouches[0].clientX - (from ?? 0);
                     if (Math.abs(dx) > 60) $wire.step(dx < 0 ? 1 : -1);
                 ">
                <img src="{{ $open->url() }}" alt="{{ $open->caption }}"
                     class="absolute inset-0 h-full w-full object-contain">
            </div>

            <div class="flex shrink-0 items-center justify-between p-4 text-white/70">
                <button type="button" wire:click="step(-1)" @disabled($this->viewerIndex === 0)
                        class="touch-target rounded-xl px-6 text-lg font-semibold disabled:opacity-25"
                        aria-label="The one before">&larr;</button>

                <span class="text-sm tabular-nums">
                    {{ ($this->viewerIndex ?? 0) + 1 }} of {{ $this->photos->count() }}
                </span>

                <button type="button" wire:click="step(1)"
                        @disabled($this->viewerIndex === $this->photos->count() - 1)
                        class="touch-target rounded-xl px-6 text-lg font-semibold disabled:opacity-25"
                        aria-label="The one after">&rarr;</button>
            </div>
        </div>
    @endif
</div>
