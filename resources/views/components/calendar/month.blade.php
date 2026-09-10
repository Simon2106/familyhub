@props(['month', 'onWall' => false, 'selected' => null])

{{-- A month at a glance.

     Dots rather than titles: at this size a title is unreadable and a dot is
     a colour you already know, so the grid answers "who is busy, and when"
     without being read. Tapping a day opens it. --}}
<div class="flex h-full min-h-0 flex-col">
    <div class="grid shrink-0 grid-cols-7 gap-1 pb-1">
        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $name)
            <span class="text-center text-xs font-semibold tracking-wide text-slate-400 uppercase">{{ $name }}</span>
        @endforeach
    </div>

    <div class="grid min-h-0 flex-1 grid-cols-7 grid-rows-6 gap-1">
        @foreach ($month['weeks'] as $week)
            @foreach ($week as $day)
                @php
                    $band = $day['closures']->first();
                @endphp

                <button
                    type="button"
                    {{-- The timeline rather than the column view: a month
                         reaches months either side of the fortnight the
                         columns are loaded for, and tapping into one of those
                         opened a day that was not there. The timeline is
                         worked out on the server and can show any date. --}}
                    x-on:click="showTimeline(@js($day['date']))"
                    wire:key="mo-{{ $day['date'] }}"
                    aria-label="{{ $day['carbon']->format('l j F') }}{{ $day['count'] ? ', '.$day['count'].' on' : '' }}"
                    class="flex min-h-0 touch-target flex-col items-stretch overflow-hidden rounded-xl border p-1 text-left transition-colors
                           {{ $day['is_today']
                               ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/40'
                               : 'border-transparent bg-white dark:bg-slate-900' }}
                           {{ $day['in_month'] ? '' : 'opacity-40' }}"
                >
                    <span class="flex items-center gap-1">
                        <span class="text-sm leading-none font-bold tabular-nums {{ $day['is_today'] ? 'text-blue-700 dark:text-blue-300' : ($day['is_past'] ? 'text-slate-400' : '') }}">
                            {{ $day['number'] }}
                        </span>

                        @if ($band)
                            {{-- The band the household lives by, carried
                                 through from the week view. --}}
                            <span class="ml-auto shrink-0 rounded px-1 text-[0.6rem] leading-tight font-bold text-white"
                                  style="background-color: {{ $band->colour() }};">{{ $band->badge() }}</span>
                        @endif
                    </span>

                    @if ($day['bins']->isNotEmpty())
                        <span class="mt-0.5 flex items-center gap-0.5">
                            @foreach ($day['bins'] as $bin)
                                <span class="size-1.5 rounded-full" style="background-color: {{ $bin->colour() }};"
                                      title="{{ $bin->label() }}"></span>
                            @endforeach
                        </span>
                    @endif

                    {{-- Under the date, not at the foot of the cell: pushed to
                         the bottom they sat closer to the next row's number
                         than to their own, and a Monday's dots read as
                         Tuesday's. --}}
                    <span class="mt-1 flex flex-wrap items-center gap-0.5">
                        @foreach (array_slice($day['colours'], 0, $onWall ? 8 : 5) as $colour)
                            <span class="size-2 rounded-full" style="background-color: {{ $colour }};"></span>
                        @endforeach
                        @if (count($day['colours']) > ($onWall ? 8 : 5))
                            <span class="text-[0.6rem] font-semibold text-slate-400">+</span>
                        @endif
                    </span>
                </button>
            @endforeach
        @endforeach
    </div>
</div>
