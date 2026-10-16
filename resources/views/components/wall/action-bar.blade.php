@props(['cells' => []])

{{--
    The four things the wall can be asked to do, as one pill.

    Four equal cells divided by hairlines rather than four separate buttons:
    on a panel read from across a kitchen a row of separate pills reads as
    four unrelated things, and this is one control with four answers.
--}}
<x-glass class="flex h-20 w-full items-stretch overflow-hidden" style="border-radius: 20px;">
    @foreach ($cells as $i => $cell)
        <button
            type="button"
            @if ($cell['click'] ?? null) x-on:click="{{ $cell['click'] }}" @endif
            class="wall-press flex flex-1 items-center gap-4 px-7 text-left
                   {{ $i > 0 ? 'border-l border-white/10' : '' }}"
            aria-label="{{ str_replace('|', ' ', $cell['label']) }}"
        >
            <x-icon :name="$cell['icon']" class="size-8 shrink-0 text-white/90"/>
            <span class="min-w-0 leading-[1.18]" style="font-size: var(--wall-weather);">
                @foreach (explode('|', $cell['label']) as $line)
                    <span class="block truncate text-white/90">{{ $line }}</span>
                @endforeach
            </span>
        </button>
    @endforeach
</x-glass>
