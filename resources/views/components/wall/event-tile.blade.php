@props([
    'event' => [],
    'colour' => '#8B5CF6',
    /** Narrow columns get a slightly smaller title so a name with an
        emoji after it fits on one line rather than ending in an ellipsis. */
    'compact' => true,
])

{{--
    One event, as a tile with a coloured spine.

    The spine rather than a tinted background: four of these stacked in a
    narrow column, each tinted its own colour, is a column nobody can read.
    A 4px edge says whose it is and leaves the words alone.
--}}
<div class="overflow-hidden rounded-[var(--radius-tile)] bg-white/[0.07] px-3 py-2"
     style="border-left: 4px solid {{ $colour }};">
    @if ($event['when'] ?? null)
        <p class="leading-tight text-white/70" style="font-size: var(--wall-pill-day);">{{ $event['when'] }}</p>
    @endif
    <p class="mt-0.5 truncate leading-tight" style="font-size: {{ $compact ? '18px' : 'var(--wall-body)' }};">{{ $event['title'] ?? '' }}</p>
    @if ($event['subtitle'] ?? null)
        <p class="mt-0.5 truncate leading-tight text-white/65" style="font-size: var(--wall-chip);">{{ $event['subtitle'] }}</p>
    @endif
</div>
