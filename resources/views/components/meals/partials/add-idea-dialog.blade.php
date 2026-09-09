{{-- Adding an idea. A name is the first tab because it is the commonest and
     the cheapest: no model, no queue, nothing to wait for. --}}
@if ($adding)
    <x-modal dismiss="$set('adding', false)" label="Add an idea">
        <x-slot:header>
            <div class="space-y-3 p-4 pb-3">
                <h3 class="text-lg font-semibold">Add an idea</h3>

                <div class="grid grid-cols-4 gap-1 rounded-xl bg-slate-100 p-1 dark:bg-slate-800">
                    @foreach (['name' => 'Name', 'url' => 'Link', 'text' => 'Text', 'photo' => 'Photo'] as $key => $label)
                        <button type="button" wire:click="$set('mode', '{{ $key }}')"
                                class="touch-target rounded-lg text-sm font-semibold {{ $mode === $key ? 'bg-white shadow-sm dark:bg-slate-900' : 'text-slate-500' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        </x-slot:header>

        <form id="add-an-idea" wire:submit="add" class="space-y-3 px-4 pb-4">
            @if ($mode === 'name')
                <input wire:model="newTitle" type="text" autofocus placeholder="Fajitas"
                       class="w-full rounded-xl border border-slate-300 px-4 py-3 text-base dark:border-slate-600 dark:bg-slate-950">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Just a name is enough. You can rate it, tag it and plan it without ever
                    writing the recipe down.
                </p>
            @elseif ($mode === 'url')
                <input wire:model="url" type="url" inputmode="url" placeholder="https://…"
                       class="w-full rounded-xl border border-slate-300 px-4 py-3 text-base dark:border-slate-600 dark:bg-slate-950">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    A recipe page, or an Instagram or TikTok post. If the link needs a login,
                    paste the caption under Text as well and we will use that instead.
                </p>
            @elseif ($mode === 'text')
                <textarea wire:model="text" rows="6" placeholder="Paste the recipe, or the caption from a post"
                          class="w-full rounded-xl border border-slate-300 px-4 py-3 text-base dark:border-slate-600 dark:bg-slate-950"></textarea>
            @else
                <input wire:model="photo" type="file" accept="image/*" capture="environment"
                       class="w-full rounded-xl border border-slate-300 p-3 text-base dark:border-slate-600 dark:bg-slate-950">
                <p class="text-sm text-slate-500 dark:text-slate-400">A cookbook page, a handwritten card, or a screenshot.</p>
            @endif

            @if ($problem)
                <p class="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">{{ $problem }}</p>
            @endif
        </form>

        <x-slot:footer>
            <div class="flex gap-2 border-t border-slate-100 p-4 pt-3 dark:border-slate-800">
                <button type="submit" form="add-an-idea"
                        class="touch-target flex-1 rounded-xl bg-blue-600 text-lg font-semibold text-white">
                    <span wire:loading.remove wire:target="add">Add</span>
                    <span wire:loading wire:target="add">Adding…</span>
                </button>
                <button type="button" wire:click="$set('adding', false)"
                        class="touch-target rounded-xl px-4 font-semibold text-slate-500">Cancel</button>
            </div>
        </x-slot:footer>
    </x-modal>
@endif
