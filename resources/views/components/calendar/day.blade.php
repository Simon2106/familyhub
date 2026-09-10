@props(['day', 'members' => null, 'onWall' => false])

{{-- One day, hour by hour.

     All-day things sit above the timeline rather than being drawn across it:
     a school holiday is not an appointment from midnight to midnight, and
     putting it in the grid would push everything real into a sliver. --}}
<div class="flex h-full min-h-0 flex-col">
    {{-- Bands and bins first, because they set the character of the day. --}}
    @if ($day['closures']->isNotEmpty() || $day['bins']->isNotEmpty() || $day['all_day']->isNotEmpty())
        <div class="shrink-0 space-y-1 pb-2">
            @foreach ($day['closures'] as $closure)
                <p class="flex items-center gap-2 rounded-xl px-3 py-1.5 text-sm font-semibold text-white"
                   style="background-color: {{ $closure->colour() }};">
                    <span class="rounded bg-black/20 px-1.5 text-xs">{{ $closure->badge() }}</span>
                    {{ $closure->label }}
                </p>
            @endforeach

            @if ($day['bins']->isNotEmpty())
                <p class="flex items-center gap-2 rounded-xl bg-slate-100 px-3 py-1.5 text-sm font-semibold dark:bg-slate-800">
                    @foreach ($day['bins'] as $bin)
                        <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $bin->colour() }};"></span>
                    @endforeach
                    {{ $day['bins']->map(fn ($bin) => $bin->label())->implode(', ') }} out
                </p>
            @endif

            @foreach ($day['all_day'] as $event)
                @php $colour = $event->members->first()?->colour ?? '#94a3b8'; @endphp
                <p class="flex items-center gap-2 rounded-xl border-l-4 bg-white px-3 py-1.5 dark:bg-slate-900"
                   style="border-color: {{ $colour }};">
                    <span class="text-xs font-semibold tracking-wide text-slate-400 uppercase">All day</span>
                    <span class="min-w-0 flex-1 truncate font-medium">{{ $event->title }}</span>
                </p>
            @endforeach
        </div>
    @endif

    <div class="pane-scroll min-h-0 flex-1 rounded-2xl bg-white dark:bg-slate-900">
        {{-- Room at the top for the first hour's label, which sits above its
             own line and was being clipped by the scroll box. --}}
        <div class="relative flex pt-3" style="min-height: {{ count($day['hours']) * ($onWall ? 4 : 3.25) + 1 }}rem;">
            {{-- The hours, as a ruler down the left. --}}
            <div class="w-14 shrink-0 sm:w-16">
                @foreach ($day['hours'] as $hour)
                    <div class="flex items-start justify-end border-t border-slate-100 pr-2 first:border-t-0 dark:border-slate-800"
                         style="height: {{ $onWall ? 4 : 3.25 }}rem;">
                        <span class="-mt-2 text-xs font-semibold tabular-nums text-slate-400">
                            {{ sprintf('%02d:00', $hour) }}
                        </span>
                    </div>
                @endforeach
            </div>

            <div class="relative min-w-0 flex-1">
                @foreach ($day['hours'] as $hour)
                    <div class="border-t border-slate-100 first:border-t-0 dark:border-slate-800"
                         style="height: {{ $onWall ? 4 : 3.25 }}rem;"></div>
                @endforeach

                @forelse ($day['timed'] as $placed)
                    @php
                        $event = $placed['event'];
                        $colour = $event->members->first()?->colour ?? '#94a3b8';
                    @endphp

                    <div class="absolute inset-x-1 overflow-hidden rounded-lg border-l-4 bg-slate-50 px-2 py-1 dark:bg-slate-800"
                         style="top: {{ $placed['top'] }}%; height: {{ $placed['height'] }}%; min-height: 1.75rem; border-color: {{ $colour }};"
                         wire:key="tl-{{ $event->id }}">
                        <p class="truncate text-xs font-semibold tabular-nums text-slate-500 dark:text-slate-400">
                            {{ $placed['starts']->format('H:i') }}
                        </p>
                        <p class="truncate leading-tight font-medium">{{ $event->title }}</p>
                        @if ($event->location)
                            <p class="truncate text-xs text-slate-400">{{ $event->location }}</p>
                        @endif
                    </div>
                @empty
                    <div class="absolute inset-0 grid place-items-center">
                        <p class="text-sm text-slate-400">Nothing in the diary.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
