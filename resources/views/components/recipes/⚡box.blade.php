<?php

use App\Jobs\ImportRecipeJob;
use App\Models\Household;
use App\Models\Recipe;
use App\Services\Capture\CaptureIntake;
use App\Services\Recipes\RecipeIntake;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The recipe box: saved meal ideas, however they arrived.
 *
 * Shown on phones at /app/recipes and on the wall's Meals tab. Cards appear the
 * moment something is saved, in a "reading it" state, so nothing shared ever
 * vanishes into a queue with no acknowledgement — the same contract the review
 * inbox makes.
 */
new class extends Component
{
    use WithFileUploads;

    /** Phones can save and edit; the wall mostly browses and stars. */
    public bool $editable = false;

    public bool $saving = false;

    public string $mode = 'url';

    public string $url = '';

    public string $text = '';

    public mixed $photo = null;

    public ?string $error = null;

    /** Empty means everything; otherwise a single tag. */
    public string $tag = '';

    public bool $favouritesOnly = false;

    public ?int $openRecipeId = null;

    public function mount(bool $editable = false): void
    {
        $this->editable = $editable;
    }

    /**
     * Every saved idea, newest first, with the ones still being read at the
     * top so they are visibly in progress rather than lost.
     *
     * @return Collection<int, Recipe>
     */
    #[Computed]
    public function recipes(): Collection
    {
        return Recipe::query()
            ->where('household_id', Household::current()->id)
            ->when($this->favouritesOnly, fn ($q) => $q->where('is_favourite', true))
            ->orderByRaw("CASE WHEN status = 'ready' THEN 1 ELSE 0 END")
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Recipe $r) => $this->tag === '' || in_array($this->tag, $r->tags ?? [], true))
            ->values();
    }

    /** Tags actually in use, so the filter row never offers an empty result. */
    #[Computed]
    public function tags(): array
    {
        return Recipe::query()
            ->where('household_id', Household::current()->id)
            ->ready()
            ->pluck('tags')
            ->flatten()
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    #[Computed]
    public function openRecipe(): ?Recipe
    {
        return $this->openRecipeId ? $this->find($this->openRecipeId) : null;
    }

    #[On('recipes-changed')]
    public function refreshRecipes(): void
    {
        unset($this->recipes, $this->tags, $this->openRecipe);
    }

    public function startSaving(string $mode = 'url'): void
    {
        $this->reset(['url', 'text', 'photo', 'error']);
        $this->mode = in_array($mode, ['url', 'text', 'photo'], true) ? $mode : 'url';
        $this->saving = true;
    }

    public function save(): void
    {
        $this->error = null;

        try {
            match ($this->mode) {
                'url' => $this->saveUrl(),
                'text' => $this->saveText(),
                'photo' => $this->savePhoto(),
            };
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->reset(['saving', 'url', 'text', 'photo']);
        unset($this->recipes);

        $this->dispatch('recipes-changed');
        $this->dispatch('saved', message: 'Saved. It will fill itself in shortly.');
    }

    protected function saveUrl(): void
    {
        $this->validate(['url' => 'required|url|max:2000']);

        // The caption is carried alongside the link rather than instead of it:
        // an Instagram link that needs a login still has a caption worth using.
        app(RecipeIntake::class)->fromUrl(trim($this->url), trim($this->text) ?: null);
    }

    protected function saveText(): void
    {
        $this->validate(['text' => 'required|string|min:5|max:100000']);

        app(RecipeIntake::class)->fromText(trim($this->text));
    }

    protected function savePhoto(): void
    {
        $this->validate([
            // HEIC comes straight off an iPhone camera and is converted before sending.
            'photo' => 'required|file|max:20480|mimetypes:image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif',
        ]);

        app(RecipeIntake::class)->fromPhoto($this->photo, trim($this->text) ?: null);
    }

    public function toggleFavourite(int $id): void
    {
        $recipe = $this->find($id);

        $recipe->forceFill(['is_favourite' => ! $recipe->is_favourite])->save();

        unset($this->recipes, $this->openRecipe);

        $this->dispatch('recipes-changed');
    }

    public function retry(int $id): void
    {
        $recipe = $this->find($id);

        $recipe->forceFill(['status' => 'pending', 'error' => null])->save();

        ImportRecipeJob::dispatch($recipe);

        unset($this->recipes);
    }

    public function open(int $id): void
    {
        $this->openRecipeId = $id;
    }

    /** Opened from a search result or a deep link. */
    #[On('show-recipe')]
    public function showRecipe(int $id): void
    {
        if (Recipe::where('household_id', Household::current()->id)->whereKey($id)->exists()) {
            $this->openRecipeId = $id;
        }
    }

    public function close(): void
    {
        $this->openRecipeId = null;
    }

    /**
     * The correction for a share that guessed wrong.
     *
     * The share target has to choose a destination without asking, so both
     * destinations owe the family a one-tap way to move it.
     */
    public function sendToReview(int $id): void
    {
        $recipe = $this->find($id);

        $recipe->source_url && blank($recipe->raw_text)
            ? app(CaptureIntake::class)->fromUrl($recipe->source_url)
            : app(CaptureIntake::class)->create('share', [
                'subject' => $recipe->title,
                'body_text' => trim($recipe->raw_text.($recipe->source_url ? "\n\nLink: ".$recipe->source_url : '')),
            ]);

        $recipe->delete();

        $this->openRecipeId = null;
        unset($this->recipes, $this->tags);

        $this->dispatch('recipes-changed');
        $this->dispatch('saved', message: 'Sent to Review instead.');
    }

    public function forget(int $id): void
    {
        if (! $this->editable) {
            return;
        }

        $this->find($id)->delete();

        $this->openRecipeId = null;
        unset($this->recipes, $this->tags);

        $this->dispatch('recipes-changed');
    }

    protected function find(int $id): Recipe
    {
        return Recipe::where('household_id', Household::current()->id)->findOrFail($id);
    }
}; ?>

{{-- Polled so a card fills itself in while somebody is still looking at it. --}}
<div class="pane-scroll h-full min-h-0" @if ($this->recipes->contains(fn ($r) => $r->status === 'pending')) wire:poll.5s @endif>

    <div class="flex flex-wrap items-center gap-2 pb-3">
        <h2 class="mr-auto text-lg font-semibold">Recipe box</h2>

        <button type="button" wire:click="$toggle('favouritesOnly')"
                class="flex touch-target items-center gap-1.5 rounded-xl px-3 text-sm font-semibold {{ $favouritesOnly ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300' : 'text-slate-500' }}">
            <svg class="size-4" fill="{{ $favouritesOnly ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                <path d="m12 3 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.8l6.5-.9z" />
            </svg>
            Favourites
        </button>

        <button type="button" wire:click="startSaving('url')"
                class="touch-target rounded-xl bg-blue-600 px-4 font-semibold text-white">
            Save a meal idea
        </button>
    </div>

    @if ($this->tags)
        <div class="flex flex-wrap gap-1.5 pb-3">
            <button type="button" wire:click="$set('tag', '')"
                    class="rounded-full px-3 py-1.5 text-sm font-medium {{ $tag === '' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">
                All
            </button>
            @foreach ($this->tags as $available)
                <button type="button" wire:click="$set('tag', '{{ $available }}')"
                        class="rounded-full px-3 py-1.5 text-sm font-medium {{ $tag === $available ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">
                    {{ $available }}
                </button>
            @endforeach
        </div>
    @endif

    @if ($this->recipes->isEmpty())
        <div class="grid place-items-center rounded-2xl border-2 border-dashed border-slate-200 p-10 text-center dark:border-slate-800">
            <p class="font-semibold text-slate-400">
                {{ $favouritesOnly || $tag !== '' ? 'Nothing matches that.' : 'No meal ideas saved yet.' }}
            </p>
            @if (! $favouritesOnly && $tag === '')
                <p class="mt-1 max-w-sm text-sm text-slate-400">
                    Share a link, a photo of a cookbook page, or an Instagram post to FamilyHub
                    and it will land here.
                </p>
            @endif
        </div>
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->recipes as $recipe)
                <article wire:key="recipe-{{ $recipe->id }}"
                         class="flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm dark:bg-slate-900">

                    @if ($recipe->imageUrl())
                        <img src="{{ $recipe->imageUrl() }}" alt="" loading="lazy"
                             class="h-32 w-full bg-slate-100 object-cover dark:bg-slate-800">
                    @endif

                    <div class="flex min-w-0 flex-1 flex-col p-3">
                        <div class="flex items-start gap-2">
                            <button type="button" wire:click="open({{ $recipe->id }})"
                                    class="min-w-0 flex-1 text-left">
                                <h3 class="font-semibold leading-tight">{{ $recipe->title }}</h3>
                                @if ($recipe->sourceLabel())
                                    <p class="mt-0.5 truncate text-xs text-slate-400">{{ $recipe->sourceLabel() }}</p>
                                @endif
                            </button>

                            <button type="button" wire:click="toggleFavourite({{ $recipe->id }})"
                                    class="grid touch-target shrink-0 place-items-center rounded-lg {{ $recipe->is_favourite ? 'text-amber-500' : 'text-slate-300 dark:text-slate-600' }}"
                                    aria-label="{{ $recipe->is_favourite ? 'Remove from favourites' : 'Add to favourites' }}"
                                    aria-pressed="{{ $recipe->is_favourite ? 'true' : 'false' }}">
                                <svg class="size-6" fill="{{ $recipe->is_favourite ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="m12 3 2.9 5.9 6.5.9-4.7 4.6 1.1 6.5-5.8-3-5.8 3 1.1-6.5L2.6 9.8l6.5-.9z" />
                                </svg>
                            </button>
                        </div>

                        @if ($recipe->status === 'pending' && ! $recipe->seemsStalled())
                            {{-- The same promise the review inbox makes: it is
                                 being read, and the card will fill itself in. --}}
                            <p class="mt-2 flex items-center gap-2 text-sm text-slate-400">
                                <svg class="size-4 animate-spin" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M12 3a9 9 0 1 0 9 9" stroke-linecap="round" />
                                </svg>
                                Reading it…
                            </p>
                        @elseif ($recipe->status === 'failed' || $recipe->seemsStalled())
                            <p class="mt-2 text-sm text-amber-700 dark:text-amber-400">
                                {{ $recipe->status === 'failed' ? $recipe->error : 'This one seems to have got stuck.' }}
                            </p>
                            <div class="mt-2 flex gap-2">
                                <button type="button" wire:click="retry({{ $recipe->id }})"
                                        class="touch-target rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white dark:bg-white dark:text-slate-900">
                                    Try again
                                </button>
                                @if ($editable)
                                    <button type="button" wire:click="forget({{ $recipe->id }})"
                                            class="touch-target rounded-xl px-3 text-sm font-semibold text-slate-500">Discard</button>
                                @endif
                            </div>
                        @else
                            @if ($recipe->summary())
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $recipe->summary() }}</p>
                            @endif

                            @if ($recipe->source_note)
                                {{-- Why the card is thin, in the model's words.
                                     Without it a caption-only recipe reads as
                                     a bug rather than as all there was. --}}
                                <p class="mt-1 text-xs text-slate-400">{{ $recipe->source_note }}</p>
                            @endif

                            @if ($recipe->tags)
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($recipe->tags as $each)
                                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $each }}</span>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    {{-- ------------------------- SAVE A MEAL IDEA ------------------------ --}}
    @if ($saving)
        {{-- Heading, mode tabs and buttons are pinned; only the field between
             them scrolls. On a phone with the keyboard up the dialog is a few
             hundred pixels tall, and as one scrolling block that left the
             title, the tabs and the field itself above the top of a panel
             nobody could tell was scrolled — which is why adding a recipe from
             a phone did not work. --}}
        <x-modal dismiss="$set('saving', false)" label="Save a meal idea">
            <x-slot:header>
                <div class="space-y-3 p-4 pb-3">
                    <h3 class="text-lg font-semibold">Save a meal idea</h3>

                    <div class="grid grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                        @foreach (['url' => 'Link', 'text' => 'Text', 'photo' => 'Photo'] as $key => $label)
                            <button type="button" wire:click="$set('mode', '{{ $key }}')"
                                    class="touch-target rounded-lg text-sm font-semibold {{ $mode === $key ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-500' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </x-slot:header>

            <x-slot:footer>
                <div class="flex gap-2 border-t border-slate-100 p-4 pt-3 dark:border-slate-800">
                    <button type="submit" form="save-meal-idea"
                            class="touch-target flex-1 rounded-xl bg-blue-600 text-lg font-semibold text-white">
                        <span wire:loading.remove wire:target="save">Save</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </button>
                    <button type="button" wire:click="$set('saving', false)"
                            class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
                </div>
            </x-slot:footer>

            <form id="save-meal-idea" wire:submit="save" class="space-y-3 px-4 pb-4">
                @if ($mode === 'url')
                    <input wire:model="url" type="url" inputmode="url" autofocus
                           placeholder="https://…"
                           class="w-full rounded-xl border border-slate-300 px-4 py-3 text-base dark:border-slate-600 dark:bg-slate-950">
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        A recipe page, or an Instagram or TikTok post. If the link needs a login,
                        paste the caption under Text as well and we will use that instead.
                    </p>
                    @error('url') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @elseif ($mode === 'text')
                    <textarea wire:model="text" rows="7" autofocus
                              placeholder="Paste the recipe, or the caption from a post"
                              class="w-full rounded-xl border border-slate-300 px-4 py-3 text-base dark:border-slate-600 dark:bg-slate-950"></textarea>
                    @error('text') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @else
                    <input wire:model="photo" type="file" accept="image/*" capture="environment"
                           class="w-full rounded-xl border border-slate-300 p-3 text-base dark:border-slate-600 dark:bg-slate-950">
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        A cookbook page, a handwritten card, or a screenshot.
                    </p>
                    @error('photo') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                @endif

                @if ($error)
                    <p class="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ $error }}</p>
                @endif
            </form>
        </x-modal>
    @endif

    {{-- ----------------------------- ONE RECIPE -------------------------- --}}
    @if ($this->openRecipe)
        @php $recipe = $this->openRecipe; @endphp

        <x-modal dismiss="close" :label="$recipe->title" width="max-w-lg">
            <div>
                @if ($recipe->imageUrl())
                    <img src="{{ $recipe->imageUrl() }}" alt="" class="h-44 w-full bg-slate-100 object-cover dark:bg-slate-800">
                @endif

                <div class="space-y-4 p-4">
                    <div class="flex items-start gap-2">
                        <h3 class="flex-1 text-xl font-bold">{{ $recipe->title }}</h3>
                        <button type="button" wire:click="close"
                                class="grid touch-target shrink-0 place-items-center rounded-xl text-slate-400" aria-label="Close">
                            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M6 6l12 12M18 6 6 18" />
                            </svg>
                        </button>
                    </div>

                    @if ($recipe->summary())
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $recipe->summary() }}</p>
                    @endif

                    @if ($recipe->source_note)
                        <p class="rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-500 dark:bg-slate-950 dark:text-slate-400">
                            {{ $recipe->source_note }}
                        </p>
                    @endif

                    @if ($recipe->hasIngredients())
                        <div>
                            <h4 class="text-sm font-semibold tracking-wide text-slate-400 uppercase">Ingredients</h4>
                            <ul class="mt-1 space-y-1">
                                @foreach ($recipe->ingredientList() as $line)
                                    <li class="flex gap-2 text-sm">
                                        <span class="w-20 shrink-0 text-right font-medium tabular-nums">
                                            {{ trim(($line['quantity'] ? rtrim(rtrim(number_format($line['quantity'], 2, '.', ''), '0'), '.') : '').' '.($line['unit'] ?? '')) }}
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            {{ $line['item'] }}@if ($line['note'])<span class="text-slate-400">, {{ $line['note'] }}</span>@endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($recipe->steps)
                        <div>
                            <h4 class="text-sm font-semibold tracking-wide text-slate-400 uppercase">Method</h4>
                            <ol class="mt-1 space-y-2">
                                @foreach ($recipe->steps as $index => $step)
                                    <li class="flex gap-3 text-sm">
                                        <span class="grid size-6 shrink-0 place-items-center rounded-full bg-slate-100 text-xs font-bold dark:bg-slate-800">{{ $index + 1 }}</span>
                                        <span class="min-w-0 flex-1">{{ $step }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    @endif

                    @if ($recipe->source_url)
                        <a href="{{ $recipe->source_url }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex touch-target items-center font-semibold text-blue-600 dark:text-blue-400">
                            Open the original
                        </a>
                    @endif

                    @if ($editable)
                        <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                            <button type="button" wire:click="sendToReview({{ $recipe->id }})"
                                    class="touch-target rounded-xl px-3 text-sm font-semibold text-slate-500">
                                Not a recipe — send to Review
                            </button>
                            <button type="button" wire:click="forget({{ $recipe->id }})"
                                    wire:confirm="Remove {{ $recipe->title }} from the recipe box?"
                                    class="touch-target ml-auto rounded-xl px-3 text-sm font-semibold text-red-600">
                                Remove
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </x-modal>
    @endif
</div>
