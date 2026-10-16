@props([
    /** @var list<array{0:string,1:string,2:string}> key, label, icon */
    'tabs' => [],
    'model' => 'tab',
    'badges' => [],
])

{{--
    The tab bar: a floating pill, and one solid block that travels.

    The block is positioned rather than the buttons, so the row stays plain
    flex and nothing has to know where anything is. It is measured from the
    live buttons after paint, which is what lets the same component carry
    six tabs or eight without being told.
--}}
<div class="pointer-events-none absolute inset-x-0 bottom-10 flex justify-center">
    <x-glass
        class="pointer-events-auto relative flex items-stretch px-2"
        style="border-radius: 999px; width: 989px; height: 96px;"
        x-data="tabBlob('{{ $model }}')"
        data-tab-bar
    >
        {{-- The travelling block. --}}
        {{-- No -translate-y-1/2 here: Tailwind 4 compiles that to the
             `translate` property, which composes with `transform` rather
             than being overridden by it — so the inline -50% and the class
             both applied and the block sat a whole height too high, clear
             of the bar it is supposed to be inside. --}}
        <div x-ref="blob" aria-hidden="true"
             class="blob pointer-events-none absolute top-1/2 left-0 rounded-[20px] bg-[#2F7CF6]"
             :style="`transform: translate3d(${blob.x}px, -50%, 0); width: ${blob.w}px; height: 80px`"
             x-show="blob.w > 0"></div>

        @foreach ($tabs as [$key, $label, $icon])
            <button
                type="button"
                data-tab="{{ $key }}"
                x-on:click="{{ $model }} = '{{ $key }}'"
                class="wall-press relative flex flex-1 flex-col items-center justify-center gap-1.5 rounded-[20px]
                       motion-quick transition-colors"
                :class="{{ $model }} === '{{ $key }}' ? 'text-white' : 'text-white/85'"
                @if (($badges[$key] ?? 0) > 0) aria-label="{{ $label }}, {{ $badges[$key] }} waiting" @endif
            >
                <span class="relative">
                    <x-icon :name="$icon" class="size-[30px]" />
                    @if (($badges[$key] ?? 0) > 0)
                        <span aria-hidden="true"
                              class="absolute -top-1.5 -right-3 grid min-w-5 place-items-center rounded-full bg-[#F2458F] px-1.5
                                     text-[13px] font-semibold text-white">
                            {{ $badges[$key] }}
                        </span>
                    @endif
                </span>
                <span style="font-size: var(--wall-tab);" class="font-medium leading-none">{{ $label }}</span>
            </button>
        @endforeach
    </x-glass>
</div>
