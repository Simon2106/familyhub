@props(['days' => [], 'live' => false])

{{-- The week, and the way into the month. --}}
<div class="flex shrink-0 items-stretch gap-2.5" style="height: 64px;">
    {{-- This week | The month --}}
    <x-glass class="flex shrink-0 items-stretch p-1.5" style="border-radius: 20px;">
        @foreach ([['week', 'This', 'week'], ['month', 'The', 'month']] as [$key, $top, $bottom])
            <button type="button"
                    @if ($live) x-on:click="{{ $key === 'month' ? 'showMonthView()' : 'showWeek()' }}" @endif
                    class="wall-press grid w-[70px] place-items-center rounded-[16px] leading-[1.15]"
                    @if ($live)
                        :class="view === '{{ $key }}' ? 'bg-white/[0.18]' : ''"
                    @elseif ($key === 'week')
                        style="background-color: rgba(255,255,255,0.18);"
                    @endif
                    style="font-size: var(--wall-pill-day);">
                <span class="text-center">
                    <span class="block text-white/75">{{ $top }}</span>
                    <span class="block font-medium">{{ $bottom }}</span>
                </span>
            </button>
        @endforeach
    </x-glass>

    {{-- Mon 12 … Sun 18 --}}
    @foreach ($days as $day)
        @php [$name, $number, $dots] = [$day[0], $day[1], $day[2]]; $isToday = $day[3] ?? false; @endphp

        <button type="button"
                @if ($live) x-on:click="pickDay('{{ $day['date'] ?? '' }}')" @endif
                wire:key="day-{{ $name }}-{{ $number }}"
                class="wall-press grid w-[69px] shrink-0 place-items-center rounded-[20px] {{ $isToday ? 'bg-[#2F7CF6]' : 'glass' }}">
            <span class="text-center leading-tight">
                <span class="block {{ $isToday ? 'text-white/85' : 'text-white/70' }}" style="font-size: var(--wall-pill-day);">{{ $name }}</span>
                <span class="block font-semibold" style="font-size: var(--wall-pill-num);">{{ $number }}</span>
                <span class="mt-1 flex h-1.5 items-center justify-center gap-1">
                    @foreach (array_slice($dots, 0, 3) as $dot)
                        <span class="size-1.5 rounded-full" style="background-color: {{ $dot }};"></span>
                    @endforeach
                </span>
            </span>
        </button>
    @endforeach
</div>
