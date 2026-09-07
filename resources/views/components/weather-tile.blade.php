@props(['forecast'])

{{-- Small on purpose. The question a kitchen asks the weather is "coat or no
     coat", and that is answered by a number and a word. --}}
@if ($forecast)
    <div {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
        <span class="text-3xl leading-none" aria-hidden="true">{{ $forecast->icon() }}</span>
        <div class="min-w-0">
            <p class="text-xl leading-tight font-bold tabular-nums">{{ $forecast->round($forecast->temperature) }}°</p>
            <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                {{ $forecast->description() }}@if ($forecast->high !== null) · {{ $forecast->round($forecast->high) }}°/{{ $forecast->round($forecast->low) }}° @endif
                @if ($forecast->mentionsRain()) · {{ $forecast->round($forecast->rainChance) }}% rain @endif
            </p>
        </div>
    </div>
@endif
