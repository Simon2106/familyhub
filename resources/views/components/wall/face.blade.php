@props([
    'household' => '',
    'clock' => '',
    'date' => '',
    'temperature' => null,
    'weather' => null,
    'weatherIcon' => 'sun',
    /** @var list<array{slug:string,name:string,colour:string,chores?:array,events?:array}> */
    'people' => [],
    /** @var list<array{0:string,1:int,2:array,3?:bool}> name, number, dots, isToday */
    'days' => [],
    'householdEvents' => [],
    'householdCount' => null,
    'todayLines' => [],
    'todos' => [],
    'live' => false,
    /** Without a speech key, asking is a button that can only disappoint. */
    'canListen' => true,
    /**
     * The household's own running concerns: the bins, the countdowns, the
     * last day of term. Small, and only when there is something to say.
     *
     * @var list<array{label:string, detail?:string, colour?:string, dots?:array}>
     */
    'notices' => [],
])

{{--
    The Home face.

    One component for the static reference at /display/style/home and for
    the live wall, so the thing that was measured against the mockup is the
    thing that ships.

    The one real departure from the brief's "scale everything by 1.21": the
    mock is 1582x994, which is 1.59:1, and the panel is 1.78:1. A uniform
    1.21 makes the artwork 1206 tall against 1080 of glass. So the scaling
    is split — x by 1.214, y by 1.087 — which lands every element on the
    same proportion of the screen the design has it on. Type keeps the
    horizontal scale, because type that shrank with the vertical would stop
    matching the sizes the brief lists; what gave way instead is the empty
    space, most of which is in the member columns.

    The heights are explicit rather than flexed. A design this specific is
    one where "roughly two thirds" is not an answer, and a column that
    grows with its content is a column that moves the add-note box.
--}}

<div class="relative h-full">

    {{-- ============================ HEADER ============================ --}}
    <header class="absolute inset-x-9 top-6 flex items-start gap-9">
        {{-- The left block is inset past the cards: the design hangs the
             clock off x=56, not off the 36px gutter the grid uses. --}}
        {{-- The household, and the time. --}}
        <div class="w-[540px] shrink-0 pl-5">
            <p class="leading-none text-white/90" style="font-size: var(--wall-name); font-weight: 400;">
                {{ $household }}
            </p>
            <p class="wall-clock mt-0.5" @if ($live) x-text="clock" @endif>{{ $clock }}</p>
        </div>

        {{-- The day, the weather, and the four things the wall can do. --}}
        {{-- The right block starts lower than the name: in the design the
             date sits level with the top of the clock, not with the
             household above it. --}}
        <div class="min-w-0 flex-1 pt-[38px]">
            <p class="leading-none" style="font-size: var(--wall-date); font-weight: 400;">{{ $date }}</p>

            @if ($temperature !== null)
                <div class="mt-3 flex items-center gap-6">
                    <x-icon :name="$weatherIcon === 'sun' ? 'sun-bright' : $weatherIcon" class="size-11 shrink-0 text-[#F5B800]" />
                    <span class="leading-none" style="font-size: var(--wall-temp); font-weight: 300;">{{ $temperature }}</span>
                    @if ($weather)
                        <span class="ml-7 truncate text-white/75" style="font-size: var(--wall-weather);">{{ $weather }}</span>
                    @endif
                </div>
            @endif

            <div class="mt-[18px] w-[1210px] max-w-full">
                @php
                    // Asking is dropped where there is nothing to listen
                    // with: a kiosk with no keyboard is the worst possible
                    // place to discover a missing setting.
                    $cells = array_values(array_filter([
                        ['icon' => 'monitor', 'label' => 'Change what the|wall is showing', 'click' => $live ? 'facePicker = ! facePicker' : null],
                        ['icon' => 'megaphone', 'label' => 'Announce|something to the house', 'click' => $live ? '$dispatch(\'wall-announce\')' : null],
                        // A microphone rather than the design's question
                        // mark: this cell is the wall's microphone, and the
                        // only one on this face — a "?" reads as help.
                        $canListen
                            ? ['icon' => 'mic', 'label' => 'Ask|a question', 'click' => $live ? '$dispatch(\'wall-listen\')' : null]
                            : null,
                        ['icon' => 'search', 'label' => 'Search', 'click' => $live ? 'searching = true' : null],
                    ]));
                @endphp

                <x-wall.action-bar :cells="$cells" />
            </div>
        </div>
    </header>

    {{-- ============================= BODY ============================= --}}
    {{-- The right panel starts 25px above the member columns, as the
         design has it: the week strip sits level with "By the hour". --}}
    <div class="absolute inset-x-9 top-[261px] grid gap-4" style="grid-template-columns: 1088px minmax(0, 1fr);">

        {{-- ------------------------- the people ------------------------- --}}
        <div class="pt-[25px]">
            {{-- A column each, plus the household's. The design has five
                 because the design's family is four; a household of two
                 should not get three empty slots. --}}
            <div class="grid gap-4"
                 style="grid-template-columns: repeat({{ max(2, count($people) + 1) }}, minmax(0, 1fr)); height: 486px;">
                @foreach ($people as $person)
                    <x-glass class="flex min-h-0 flex-col p-2.5" wire:key="col-{{ $person['slug'] ?? $loop->index }}">
                        <header class="flex shrink-0 items-center gap-2">
                            <x-wall.avatar
                                :slug="$person['slug'] ?? null"
                                :name="$person['name']"
                                :colour="$person['colour']"
                                :size="72"
                            />
                            <span class="min-w-0 truncate" style="font-size: var(--wall-member); font-weight: 500;">
                                {{ $person['name'] }}
                            </span>
                        </header>

                        <div class="mt-3 min-h-0 flex-1 space-y-2 overflow-hidden">
                            @foreach ($person['chores'] ?? [] as $chore)
                                <div class="flex items-center gap-1.5 rounded-[var(--radius-tile)] bg-white/[0.07] px-1.5 py-1.5">
                                    <span class="grid size-5 shrink-0 place-items-center rounded-full bg-[#34C759]">
                                        <x-icon name="check" class="size-3 text-white" />
                                    </span>
                                    <span class="min-w-0 truncate text-white/90" style="font-size: 14px;">{{ $chore }}</span>
                                </div>
                            @endforeach

                            @foreach ($person['events'] ?? [] as $event)
                                <x-wall.event-tile :event="$event" :colour="$person['colour']" />
                            @endforeach

                            @if (($person['chores'] ?? []) === [] && ($person['events'] ?? []) === [])
                                <p class="wall-dim" style="font-size: var(--wall-body);">Nothing on</p>
                            @endif
                        </div>
                    </x-glass>
                @endforeach

                {{-- The household's own column. A smaller disc than a face,
                     which is what leaves room for the word beside it. --}}
                <div class="relative flex min-h-0 flex-col">
                    <button type="button"
                            @if ($live) x-on:click="showTimeline(selected)" @endif
                            class="wall-press absolute -top-[25px] right-0 leading-none text-[#4D9BFF]"
                            style="font-size: var(--wall-pill-day);">
                        By the hour
                    </button>

                    <x-glass class="flex min-h-0 flex-1 flex-col p-2">
                        {{-- A smaller disc than a face. That is what leaves
                             room for the word beside it in a column this
                             narrow, and it is what the design does too. --}}
                        <header class="flex shrink-0 items-center gap-1.5">
                            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-white/15">
                                <x-icon name="house" class="size-[22px] text-white" />
                            </span>
                            <span class="min-w-0 flex-1 truncate" style="font-size: var(--wall-member); font-weight: 500;">Household</span>
                            @if ($householdCount)
                                <span class="shrink-0 rounded-full bg-white/[0.12] px-1.5 py-0.5 leading-none text-white/80"
                                      style="font-size: var(--wall-pill-day);">{{ $householdCount }}</span>
                            @endif
                        </header>

                        <div class="mt-3 min-h-0 flex-1 space-y-2 overflow-hidden">
                            {{-- The bins, the countdowns, the last day of
                                 term. These used to be chips along the
                                 header; the design has no header to put
                                 them in, and the household's own column is
                                 where a household's own business belongs. --}}
                            @foreach ($notices as $notice)
                                <div class="flex items-center gap-2 rounded-[var(--radius-tile)] px-2 py-1.5"
                                     style="background-color: {{ $notice['colour'] ?? 'rgba(255,255,255,0.07)' }};">
                                    @if ($notice['dots'] ?? null)
                                        <span class="flex shrink-0 items-center gap-1" aria-hidden="true">
                                            @foreach ($notice['dots'] as $dot)
                                                <span class="size-2.5 rounded-full" style="background-color: {{ $dot }};"></span>
                                            @endforeach
                                        </span>
                                    @endif
                                    <span class="min-w-0 flex-1 truncate" style="font-size: 14px;">{{ $notice['label'] }}</span>
                                    @if ($notice['detail'] ?? null)
                                        <span class="shrink-0 text-white/70" style="font-size: 13px;">{{ $notice['detail'] }}</span>
                                    @endif
                                </div>
                            @endforeach

                            @forelse ($householdEvents as $event)
                                <x-wall.event-tile :event="$event" :colour="$event['colour'] ?? '#F2458F'" />
                            @empty
                                @if ($notices === [])
                                    <p class="wall-dim" style="font-size: var(--wall-body);">Nothing on</p>
                                @endif
                            @endforelse
                        </div>
                    </x-glass>
                </div>
            </div>

            {{-- Add a note, under the people. --}}
            <div class="mt-3">
                <button type="button"
                        @if ($live) x-on:click="$dispatch('add-note')" @endif
                        class="wall-press grid place-items-center rounded-[20px] border-2 border-dashed border-white/25"
                        style="width: 409px; height: 196px;">
                    <span class="text-center">
                        <x-icon name="plus" class="mx-auto size-9 text-white" />
                        <span class="mt-1.5 block" style="font-size: var(--wall-member);">Add a note</span>
                        <span class="mt-1 block text-white/60" style="font-size: var(--wall-chip);">e.g. Gran&rsquo;s here at 4</span>
                    </span>
                </button>
            </div>
        </div>

        {{-- ----------------------- the right panel ---------------------- --}}
        <div class="flex flex-col gap-3">
            <x-wall.week-strip :days="$days" :live="$live" />

            {{-- TODAY --}}
            <x-glass class="flex flex-col overflow-hidden p-5" style="height: 335px;">
                <p class="wall-label shrink-0">Today</p>

                <div class="mt-3.5 min-h-0 flex-1 overflow-hidden">
                    @forelse ($todayLines as $line)
                        <div class="flex gap-4" wire:key="today-{{ $loop->index }}">
                            <span class="w-[100px] shrink-0 pt-px text-white/80" style="font-size: var(--wall-body);">
                                {{ $line['when'] }}
                            </span>

                            {{-- A dot on a line, so three events read as one
                                 day rather than three unrelated rows. The
                                 line runs the full height of every row but
                                 the last, which is what joins them up. --}}
                            <span class="relative flex w-3 shrink-0 justify-center">
                                <span class="absolute top-0 bottom-0 w-0.5 bg-white/15 {{ $loop->last ? 'h-3' : '' }}"></span>
                                <span class="relative mt-1 size-3 rounded-full"
                                      style="background-color: {{ $line['colour'] }};"></span>
                            </span>

                            <span class="min-w-0 flex-1 pb-6">
                                <span class="block truncate leading-tight" style="font-size: 24px;">{{ $line['title'] }}</span>
                                @if ($line['subtitle'] ?? null)
                                    <span class="mt-1 block truncate leading-tight text-white/65" style="font-size: 18px;">{{ $line['subtitle'] }}</span>
                                @endif
                            </span>
                        </div>
                    @empty
                        <p class="wall-dim" style="font-size: var(--wall-body);">Nothing on this day.</p>
                    @endforelse
                </div>
            </x-glass>

            {{-- TO DO --}}
            <x-glass class="overflow-hidden p-5" style="height: 124px;">
                <div class="flex items-start justify-between gap-4">
                    <p class="wall-label">To do</p>
                    <button type="button"
                            @if ($live) x-on:click="$dispatch('add-todo')" @endif
                            class="wall-press -mt-1.5 shrink-0" aria-label="Add something to do">
                        <x-icon name="plus" class="size-7 text-white" />
                    </button>
                </div>

                <div class="mt-2.5 space-y-1.5">
                    @forelse ($todos as $todo)
                        <p class="truncate" style="font-size: var(--wall-body);" wire:key="todo-{{ $loop->index }}">{{ $todo }}</p>
                    @empty
                        <p class="wall-dim" style="font-size: var(--wall-body);">Nothing to do.</p>
                    @endforelse
                </div>
            </x-glass>
        </div>
    </div>
</div>
