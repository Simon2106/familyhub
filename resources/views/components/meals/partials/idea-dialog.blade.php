{{-- One idea, and everything the family knows about it. --}}
@if ($this->openIdea)
    @php $idea = $this->openIdea; @endphp

    <x-modal dismiss="shut" :label="$idea->title" width="max-w-lg">
        <x-slot:header>
            <div class="flex items-start gap-3 p-4 pb-3">
                @if ($idea->imageUrl())
                    <img src="{{ $idea->imageUrl() }}" alt="" class="size-14 shrink-0 rounded-xl object-cover">
                @endif

                <div class="min-w-0 flex-1">
                    <h3 class="text-lg font-semibold">{{ $idea->title }}</h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        @if ($idea->neverCooked())
                            Never tried
                        @else
                            Cooked {{ $idea->timesCooked() }} {{ Str::plural('time', $idea->timesCooked()) }}@if ($idea->lastCooked()), last on {{ $idea->lastCooked()->format('j M Y') }}@endif
                        @endif
                    </p>
                </div>

                <button type="button" wire:click="toggleFavourite({{ $idea->id }})"
                        class="grid touch-target shrink-0 place-items-center rounded-xl text-2xl {{ $idea->is_favourite ? 'text-rose-500' : 'text-slate-300 dark:text-slate-600' }}"
                        aria-label="{{ $idea->is_favourite ? 'Remove from favourites' : 'Add to favourites' }}">
                    {!! $idea->is_favourite ? '&#10084;' : '&#9825;' !!}
                </button>
            </div>
        </x-slot:header>

        <div class="space-y-4 px-4 pb-4">
            {{-- Stars, one row per adult, filled to what this one gave. --}}
            <div>
                <p class="text-sm font-semibold">
                    What did you think?
                    @if ($idea->stars())
                        <span class="ml-1 font-normal text-slate-500 dark:text-slate-400">
                            {{ number_format($idea->stars(), 1) }} from {{ $idea->stars_count }} {{ Str::plural('adult', $idea->stars_count) }}
                        </span>
                    @endif
                </p>

                @if ($this->me)
                    <div class="mt-1 flex gap-1">
                        @for ($star = 1; $star <= 5; $star++)
                            <button type="button" wire:click="rate({{ $idea->id }}, {{ $star }})"
                                    class="grid size-11 place-items-center text-2xl {{ ($this->myStars ?? 0) >= $star ? 'text-amber-400' : 'text-slate-300 dark:text-slate-600' }}"
                                    aria-label="{{ $star }} {{ Str::plural('star', $star) }}">
                                {!! ($this->myStars ?? 0) >= $star ? '&#9733;' : '&#9734;' !!}
                            </button>
                        @endfor
                    </div>
                @else
                    <p class="mt-1 text-sm text-slate-400">Link this login to a family member in Settings to rate.</p>
                @endif

                @if ($idea->kidsVerdict())
                    <p class="mt-1 flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                        <x-icon class="size-4 {{ ['up' => 'text-emerald-500', 'down' => 'text-rose-500', 'mixed' => 'text-slate-400'][$idea->kidsVerdict()] }}"
                                :name="['up' => 'thumb-up', 'down' => 'thumb-down', 'mixed' => 'thumbs-split'][$idea->kidsVerdict()]" />
                        The children {{ ['up' => 'liked it', 'down' => 'were not keen', 'mixed' => 'were split'][$idea->kidsVerdict()] }}
                        ({{ $idea->thumbs_up }} up, {{ $idea->thumbs_down }} down)
                    </p>
                @endif
            </div>

            {{-- Tags --}}
            <div>
                <p class="text-sm font-semibold">Tags</p>
                <div class="mt-1 flex flex-wrap gap-1">
                    @foreach (array_unique(array_merge(\App\Models\Recipe::SUGGESTED_TAGS, array_map('mb_strtolower', $idea->tags ?? []))) as $tag)
                        <button type="button" wire:click="toggleTag({{ $idea->id }}, '{{ $tag }}')"
                                class="touch-target rounded-full px-3 text-sm font-medium {{ $idea->hasTag($tag) ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">
                            {{ $tag }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- The family's own note: the thing no recipe site will ever say. --}}
            <div>
                <label class="block text-sm font-semibold" for="idea-note">Notes</label>
                <textarea id="idea-note" wire:model="noteDraft" rows="2"
                          placeholder="“Joey won’t eat the sauce”"
                          class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-base dark:border-slate-600 dark:bg-slate-950"></textarea>
                <button type="button" wire:click="saveNote"
                        class="touch-target rounded-xl px-3 text-sm font-semibold text-blue-600 dark:text-blue-400">Save note</button>
            </div>

            {{-- Collections --}}
            <div>
                <p class="text-sm font-semibold">Lists</p>
                <div class="mt-1 flex flex-wrap gap-1">
                    @foreach ($this->collections as $collection)
                        <button type="button" wire:click="toggleCollection({{ $idea->id }}, {{ $collection->id }})"
                                class="touch-target rounded-full px-3 text-sm font-medium {{ $idea->collections->contains($collection->id) ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400' }}">
                            {{ $collection->name }}
                        </button>
                    @endforeach
                </div>
                <div class="mt-2 flex gap-2">
                    <input wire:model="collectionName" type="text" placeholder="New list — “Sunday roasts”"
                           class="min-w-0 flex-1 rounded-xl border border-slate-300 px-3 py-2 text-base dark:border-slate-600 dark:bg-slate-950">
                    <button type="button" wire:click="addCollection"
                            class="touch-target rounded-xl bg-slate-100 px-3 text-sm font-semibold dark:bg-slate-800">Add list</button>
                </div>
            </div>

            @if ($idea->hasIngredients())
                <div>
                    <p class="text-sm font-semibold">Ingredients</p>
                    <ul class="mt-1 space-y-0.5 text-sm text-slate-600 dark:text-slate-300">
                        @foreach ($idea->ingredientList() as $line)
                            <li>{{ trim(($line['quantity'] ? rtrim(rtrim(number_format($line['quantity'], 2, '.', ''), '0'), '.') : '').' '.($line['unit'] ?? '').' '.$line['item']) }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($idea->source_url)
                <a href="{{ $idea->source_url }}" target="_blank" rel="noopener noreferrer"
                   class="inline-flex touch-target items-center font-semibold text-blue-600 dark:text-blue-400">Open the original</a>
            @endif
        </div>

        <x-slot:footer>
            <div class="flex gap-2 border-t border-slate-100 p-4 pt-3 dark:border-slate-800">
                <button type="button" wire:click="shut"
                        class="touch-target flex-1 rounded-xl bg-slate-100 font-semibold dark:bg-slate-800">Close</button>
                <button type="button" wire:click="forget({{ $idea->id }})"
                        wire:confirm="Remove {{ $idea->title }} from the ideas?"
                        class="touch-target rounded-xl px-4 text-sm font-semibold text-red-600">Remove</button>
            </div>
        </x-slot:footer>
    </x-modal>
@endif
