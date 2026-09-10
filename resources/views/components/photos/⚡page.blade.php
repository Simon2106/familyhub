<?php

use App\Models\Household;
use App\Models\Photo;
use App\Services\PhotoLibrary;
use Illuminate\Support\Collection;
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

<div class="app-shell flex flex-col">
    <header class="flex shrink-0 items-center gap-3 px-4 pt-4 pb-2">
        <a href="{{ route('app') }}" wire:navigate
           class="grid touch-target place-items-center rounded-xl bg-white text-slate-500 dark:bg-slate-900" aria-label="Back">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m15 18-6-6 6-6" />
            </svg>
        </a>
        <h1 class="flex-1 text-2xl font-bold">Photos</h1>
    </header>

    <div x-data="{ show: false, message: '' }"
         x-on:saved.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 2500)"
         x-show="show" x-cloak x-transition
         class="fixed inset-x-4 top-4 z-50 rounded-xl bg-slate-900 px-4 py-3 text-white shadow-lg dark:bg-white dark:text-slate-900">
        <span x-text="message"></span>
    </div>

    <div class="pane-scroll min-h-0 flex-1 px-4 pb-8">
        <form wire:submit="save" class="rounded-2xl bg-white p-3 dark:bg-slate-900">
            <label class="block">
                <span class="block text-sm font-medium">Add photographs</span>
                <input wire:model="uploads" type="file" accept="image/*" multiple
                       class="mt-1 w-full rounded-xl border border-slate-300 p-3 text-base dark:border-slate-600 dark:bg-slate-950">
            </label>
            @error('uploads.*') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror

            <button type="submit" class="mt-2 touch-target w-full rounded-xl bg-blue-600 font-semibold text-white">
                <span wire:loading.remove wire:target="save,uploads">Add to the wall</span>
                <span wire:loading wire:target="save,uploads">Adding…</span>
            </button>

            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                A shared iCloud album can be added in Settings → Wall display, and is fetched
                overnight.
            </p>
        </form>

        @if ($this->hiddenCount > 0)
            <label class="mt-3 flex touch-target items-center gap-2">
                <input wire:model.live="showHidden" type="checkbox" class="size-5 rounded">
                <span class="text-sm font-medium">Show the {{ $this->hiddenCount }} hidden</span>
            </label>
        @endif

        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
            @forelse ($this->photos as $photo)
                <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900" wire:key="photo-{{ $photo->id }}">
                    <img src="{{ $photo->url() }}" alt="{{ $photo->caption }}" loading="lazy"
                         class="aspect-square w-full object-cover {{ $photo->is_hidden ? 'opacity-30' : '' }}">

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
                <div class="col-span-full rounded-2xl border-2 border-dashed border-slate-200 p-8 text-center dark:border-slate-700">
                    <p class="font-semibold text-slate-400">No photographs yet.</p>
                    <p class="mt-1 text-sm text-slate-400">Add some above, or link a shared album in Settings.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
