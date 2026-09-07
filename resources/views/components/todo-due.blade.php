@props(['item', 'today' => null])

{{-- The deadline, said the same way everywhere a to-do appears.

     Emphasis climbs as the date nears rather than being either "normal" or
     "red": across a kitchen the weight of the text is what is legible first,
     so a form due next week reads as calm and one due tomorrow does not. --}}

@php
    $urgency = $item->urgency($today);
    $badge = $item->dueBadge($today);

    $tone = match ($urgency) {
        'overdue' => 'font-bold text-red-600 dark:text-red-400',
        'today' => 'font-bold text-red-600 dark:text-red-400',
        'tomorrow' => 'font-semibold text-amber-600 dark:text-amber-400',
        'soon' => 'font-medium text-amber-600 dark:text-amber-500',
        default => 'text-slate-400',
    };
@endphp

@if ($badge)
    <span {{ $attributes->merge(['class' => 'block text-sm '.$tone]) }}>
        {{ $badge }}
        @if ($item->event)
            <span class="font-normal text-slate-400">· for {{ $item->event->title }}</span>
        @endif
    </span>
@elseif ($item->event)
    <span {{ $attributes->merge(['class' => 'block text-sm text-slate-400']) }}>for {{ $item->event->title }}</span>
@endif
