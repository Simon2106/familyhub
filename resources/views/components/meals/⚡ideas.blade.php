<?php

use App\Models\Household;
use App\Models\MealCollection;
use App\Models\Member;
use App\Models\Recipe;
use App\Services\Meals\MealFeedback;
use App\Services\Recipes\RecipeIntake;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The idea box: everything the family might cook, and what they think of it.
 *
 * A free-text idea is the same kind of thing as a fetched recipe card — one
 * row, a title, and as much else as anybody ever bothered to add. "That
 * chicken thing" can be rated, tagged, filed and planned without ever becoming
 * a recipe, which is the difference between a box a family uses and a box a
 * family means to get round to filling in.
 */
new class extends Component
{
    use WithFileUploads;

    /** all | favourites | never | while | quick | tag:x | collection:1 */
    #[Url(as: 'show', except: 'all')]
    public string $filter = 'all';

    /** recent | rating | cooked | name */
    #[Url(as: 'by', except: 'recent')]
    public string $order = 'recent';

    public ?int $openIdeaId = null;

    /* ------------------------------ adding ------------------------------- */

    public bool $adding = false;

    /** name | url | text | photo */
    public string $mode = 'name';

    public string $newTitle = '';

    public string $url = '';

    public string $text = '';

    public $photo = null;

    public ?string $problem = null;

    /* ------------------------------ editing ------------------------------ */

    public string $noteDraft = '';

    public string $collectionName = '';

    public function household(): Household
    {
        return Household::current();
    }

    /** Who is doing the rating. Null when the login is not tied to an adult. */
    #[Computed]
    public function me(): ?Member
    {
        return auth()->user()?->asMember();
    }

    /* ------------------------------ reading ------------------------------ */

    /** @return Collection<int, Recipe> */
    #[Computed]
    public function ideas(): Collection
    {
        $today = $this->household()->todayLocal();

        $ideas = Recipe::query()
            ->where('household_id', $this->household()->id)
            ->withCookedHistory($today->toDateString())
            ->withOpinions()
            ->with('collections')
            ->when(str_starts_with($this->filter, 'tag:'),
                fn ($q) => $q->tagged(substr($this->filter, 4)))
            ->when(str_starts_with($this->filter, 'collection:'),
                fn ($q) => $q->whereHas('collections',
                    fn ($c) => $c->where('meal_collections.id', (int) substr($this->filter, 11))))
            ->when($this->filter === 'favourites', fn ($q) => $q->where('is_favourite', true))
            ->get();

        // Filters that depend on history are applied in PHP: they are counts
        // and dates the query has already fetched, and re-expressing them as
        // SQL would be two ways of saying the same thing.
        $ideas = match ($this->filter) {
            'never' => $ideas->filter(fn (Recipe $r) => $r->isReady() && $r->neverCooked()),
            'while' => $ideas->filter(fn (Recipe $r) => $r->isReady()
                && $r->lastCooked() !== null
                && $r->lastCooked()->lessThan($today->subDays(Recipe::A_WHILE_DAYS))),
            'quick' => $ideas->filter(fn (Recipe $r) => $r->hasTag('quick')),
            default => $ideas,
        };

        return $this->sorted($ideas)->values();
    }

    /** @return Collection<int, Recipe> */
    protected function sorted(Collection $ideas): Collection
    {
        return match ($this->order) {
            // Unrated last rather than first: a nought is not an opinion.
            'rating' => $ideas->sortByDesc(fn (Recipe $r) => $r->stars() ?? -1),
            'cooked' => $ideas->sortByDesc(fn (Recipe $r) => $r->timesCooked()),
            'name' => $ideas->sortBy(fn (Recipe $r) => mb_strtolower($r->title)),
            default => $ideas->sortByDesc(fn (Recipe $r) => $r->id),
        };
    }

    /** Every tag in use, so the filter bar shows what the box actually has. */
    #[Computed]
    public function tagsInUse(): array
    {
        return Recipe::query()
            ->where('household_id', $this->household()->id)
            ->pluck('tags')
            ->flatten()
            ->filter()
            ->map(fn ($tag) => mb_strtolower((string) $tag))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return Collection<int, MealCollection> */
    #[Computed]
    public function collections(): Collection
    {
        return MealCollection::query()
            ->where('household_id', $this->household()->id)
            ->withCount('recipes')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function openIdea(): ?Recipe
    {
        if (! $this->openIdeaId) {
            return null;
        }

        return Recipe::query()
            ->where('household_id', $this->household()->id)
            ->withCookedHistory($this->household()->todayLocal()->toDateString())
            ->withOpinions()
            ->with(['collections', 'ratings.member'])
            ->find($this->openIdeaId);
    }

    /** This adult's own stars for the open idea, so the row shows filled. */
    #[Computed]
    public function myStars(): ?int
    {
        return $this->openIdea?->ratings
            ->firstWhere('member_id', $this->me?->id)?->stars;
    }

    /* ------------------------------ writing ------------------------------ */

    public function inspect(int $id): void
    {
        $this->openIdeaId = $id;
        $this->noteDraft = (string) ($this->openIdea?->notes ?? '');

        unset($this->openIdea, $this->myStars);
    }

    public function shut(): void
    {
        $this->openIdeaId = null;
        $this->noteDraft = '';

        unset($this->openIdea, $this->myStars);
    }

    public function toggleFavourite(int $id): void
    {
        $idea = $this->find($id);
        $idea->update(['is_favourite' => ! $idea->is_favourite]);

        $this->forgetAll();
    }

    public function rate(int $id, int $stars): void
    {
        if (! $this->me) {
            $this->problem = 'Link this login to a family member in Settings before rating.';

            return;
        }

        app(MealFeedback::class)->star($this->find($id), $this->me, $stars);

        $this->forgetAll();
    }

    public function saveNote(): void
    {
        if ($idea = $this->openIdea) {
            $idea->update(['notes' => trim($this->noteDraft) ?: null]);

            $this->forgetAll();
            $this->dispatch('saved', message: 'Note saved.');
        }
    }

    public function toggleTag(int $id, string $tag): void
    {
        $idea = $this->find($id);
        $tags = collect($idea->tags ?? [])->map(fn ($t) => mb_strtolower((string) $t));

        $idea->update(['tags' => $tags->contains($tag)
            ? $tags->reject(fn ($t) => $t === $tag)->values()->all()
            : $tags->push($tag)->unique()->values()->all()]);

        $this->forgetAll();
    }

    public function toggleCollection(int $id, int $collectionId): void
    {
        $this->find($id)->collections()->toggle([$collectionId]);

        $this->forgetAll();
    }

    public function addCollection(): void
    {
        $name = trim($this->collectionName);

        if ($name === '') {
            return;
        }

        MealCollection::firstOrCreate([
            'household_id' => $this->household()->id,
            'name' => $name,
        ]);

        $this->collectionName = '';
        $this->forgetAll();
    }

    public function forget(int $id): void
    {
        $this->find($id)->delete();

        $this->shut();
        $this->forgetAll();
        $this->dispatch('recipes-changed');
    }

    /* ------------------------------- adding ------------------------------ */

    public function add(): void
    {
        $this->problem = null;

        try {
            match ($this->mode) {
                // The first-class case: a name and nothing else. No model, no
                // queue, no waiting — "fajitas" is a complete idea.
                'name' => $this->addByName(),
                'url' => app(RecipeIntake::class)->fromUrl(trim($this->url)),
                'text' => app(RecipeIntake::class)->fromText(trim($this->text)),
                'photo' => app(RecipeIntake::class)->fromPhoto($this->photo),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->reset('newTitle', 'url', 'text', 'photo');
        $this->adding = false;

        $this->forgetAll();
        $this->dispatch('recipes-changed');
        $this->dispatch('saved', message: 'Added to the ideas.');
    }

    protected function addByName(): void
    {
        $title = trim($this->newTitle);

        if ($title === '') {
            throw new \RuntimeException('Give it a name.');
        }

        Recipe::create([
            'household_id' => $this->household()->id,
            'title' => $title,
            'source_kind' => 'text',
            'status' => 'ready',
        ]);
    }

    #[On('recipes-changed')]
    public function refreshIdeas(): void
    {
        $this->forgetAll();
    }

    protected function forgetAll(): void
    {
        unset($this->ideas, $this->tagsInUse, $this->collections, $this->openIdea, $this->myStars);
    }

    protected function find(int $id): Recipe
    {
        return Recipe::where('household_id', $this->household()->id)->findOrFail($id);
    }
}; ?>

@php $today = $this->household()->todayLocal(); @endphp

<div>
    {{-- Filters. One scrolling rail rather than a panel: on a phone this is
         the difference between a box you skim and a box you configure. --}}
    <div class="pane-scroll -mx-4 flex gap-2 overflow-x-auto px-4 pb-2">
        @php
            $chips = [
                'all' => 'All',
                'favourites' => 'Favourites',
                'while' => 'Not had for a while',
                'never' => 'Never tried',
                'quick' => 'Quick',
            ];
        @endphp

        @foreach ($chips as $key => $label)
            <button type="button" wire:click="$set('filter', '{{ $key }}')"
                    class="touch-target shrink-0 rounded-full px-4 text-sm font-semibold {{ $filter === $key ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300' }}">
                {{ $label }}
            </button>
        @endforeach

        @foreach ($this->collections as $collection)
            <button type="button" wire:click="$set('filter', 'collection:{{ $collection->id }}')"
                    class="touch-target shrink-0 rounded-full px-4 text-sm font-semibold {{ $filter === 'collection:'.$collection->id ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-white text-slate-600 dark:bg-slate-900 dark:text-slate-300' }}">
                {{ $collection->name }}
                <span class="opacity-50">{{ $collection->recipes_count }}</span>
            </button>
        @endforeach

        @foreach ($this->tagsInUse as $tag)
            <button type="button" wire:click="$set('filter', 'tag:{{ $tag }}')"
                    class="touch-target shrink-0 rounded-full px-4 text-sm font-medium {{ $filter === 'tag:'.$tag ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-white text-slate-500 dark:bg-slate-900 dark:text-slate-400' }}">
                #{{ $tag }}
            </button>
        @endforeach
    </div>

    <div class="flex items-center gap-2 pb-3">
        <select wire:model.live="order"
                class="touch-target rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold dark:border-slate-700 dark:bg-slate-900">
            <option value="recent">Newest first</option>
            <option value="rating">Best rated</option>
            <option value="cooked">Most cooked</option>
            <option value="name">By name</option>
        </select>

        <span class="text-sm text-slate-400">{{ $this->ideas->count() }} {{ Str::plural('idea', $this->ideas->count()) }}</span>

        <button type="button" wire:click="$set('adding', true)"
                class="ml-auto touch-target rounded-xl bg-blue-600 px-4 font-semibold text-white">Add</button>
    </div>

    @if ($problem)
        <p class="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">{{ $problem }}</p>
    @endif

    @forelse ($this->ideas as $idea)
        @if ($loop->first)
            <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        @endif

        <li wire:key="idea-{{ $idea->id }}">
            <button type="button" wire:click="inspect({{ $idea->id }})"
                    class="flex w-full gap-3 rounded-2xl bg-white p-3 text-left dark:bg-slate-900">
                @if ($idea->imageUrl())
                    <img src="{{ $idea->imageUrl() }}" alt="" class="size-16 shrink-0 rounded-xl object-cover">
                @endif

                <span class="min-w-0 flex-1">
                    <span class="flex items-start gap-2">
                        <span class="min-w-0 flex-1 font-semibold">{{ $idea->title }}</span>
                        @if ($idea->is_favourite)
                            <span class="shrink-0 text-rose-500" aria-label="Favourite">&#10084;</span>
                        @endif
                    </span>

                    <span class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm text-slate-500 dark:text-slate-400">
                        @if ($idea->stars())
                            <span class="font-semibold text-amber-500">{{ number_format($idea->stars(), 1) }}&#9733;</span>
                        @endif

                        @if ($idea->kidsVerdict())
                            <x-icon class="inline-block size-4 align-[-0.15em] {{ ['up' => 'text-emerald-500', 'down' => 'text-rose-500', 'mixed' => 'text-slate-400'][$idea->kidsVerdict()] }}"
                                    :label="'What the children think'"
                                    :name="['up' => 'thumb-up', 'down' => 'thumb-down', 'mixed' => 'thumbs-split'][$idea->kidsVerdict()]" />
                        @endif

                        @if ($idea->neverCooked())
                            <span>Never tried</span>
                        @else
                            <span>{{ $idea->timesCooked() }}&times;</span>
                            @if ($idea->lastCooked())
                                <span>last {{ $idea->lastCooked()->diffForHumans(['short' => true, 'parts' => 1]) }}</span>
                            @endif
                        @endif

                        @if ($idea->status !== 'ready')
                            <span class="text-amber-600 dark:text-amber-400">{{ $idea->status === 'failed' ? 'Could not be read' : 'Reading it…' }}</span>
                        @endif
                    </span>

                    @if ($idea->tags)
                        <span class="mt-1 flex flex-wrap gap-1">
                            @foreach (array_slice($idea->tags, 0, 4) as $tag)
                                <span class="rounded-md bg-slate-100 px-1.5 text-xs text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ $tag }}</span>
                            @endforeach
                        </span>
                    @endif
                </span>
            </button>
        </li>

        @if ($loop->last)
            </ul>
        @endif
    @empty
        <div class="rounded-2xl border-2 border-dashed border-slate-200 p-8 text-center dark:border-slate-700">
            <p class="font-semibold text-slate-400">
                {{ $filter === 'all' ? 'No ideas yet.' : 'Nothing matches that filter.' }}
            </p>
            <p class="mt-1 text-sm text-slate-400">
                {{ $filter === 'all' ? 'Add one by name — “fajitas” is a perfectly good idea.' : 'Try another one.' }}
            </p>
        </div>
    @endforelse

    @include('components.meals.partials.idea-dialog')
    @include('components.meals.partials.add-idea-dialog')
</div>
