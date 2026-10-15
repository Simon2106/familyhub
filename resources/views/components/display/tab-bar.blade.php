@props([
    /** @var list<array{0:string,1:string,2:string}> key, label, icon */
    'tabs' => [],
    /** The Alpine expression holding the active key. */
    'model' => 'tab',
    /** Rendered as a badge on one tab, keyed by tab key. */
    'badges' => [],
])

{{--
    The tab bar, as one travelling blob.

    A fade out of one place and in at another is two events the eye reads as
    a blink; a single shape that slides and stretches between them is one
    event it follows. Measured with getBoundingClientRect and moved with a
    transform, which is a FLIP in all but name and is the only way to animate
    width and position on a Pi without repainting the row.

    The blob is positioned, never the buttons — so the row's layout is plain
    flex and nothing has to know where anything is.
--}}

<div x-data="tabBlob('{{ $model }}')" class="relative flex items-center gap-1" data-tab-bar>
    {{-- The blob, behind everything. --}}
    <div x-ref="blob" aria-hidden="true"
         class="blob pointer-events-none absolute top-0 bottom-0 left-0 rounded-full bg-slate-900/85 dark:bg-white/90"
         :style="`transform: translate3d(${blob.x}px, 0, 0); width: ${blob.w}px`"
         x-show="blob.w > 0"></div>

    @foreach ($tabs as [$key, $label, $icon])
        <button
            type="button"
            x-ref="tab-{{ $key }}"
            data-tab="{{ $key }}"
            x-on:click="{{ $model }} = '{{ $key }}'"
            class="press relative flex min-h-16 flex-col items-center justify-center gap-0.5 rounded-full px-7
                   motion-quick transition-colors"
            :class="{{ $model }} === '{{ $key }}'
                ? 'text-white dark:text-slate-900'
                : 'text-slate-900/60 dark:text-white/60'"
            @if (($badges[$key] ?? 0) > 0) aria-label="{{ $label }}, {{ $badges[$key] }} waiting" @endif
        >
            <span class="relative">
                <x-icon :name="$icon" class="size-8" />
                @if (($badges[$key] ?? 0) > 0)
                    <span aria-hidden="true"
                          class="absolute -top-1 -right-3 grid min-w-6 place-items-center rounded-full bg-blue-600 px-1.5
                                 text-[0.9375rem] font-semibold tabular-nums text-white">
                        {{ $badges[$key] }}
                    </span>
                @endif
            </span>
            <span class="text-[length:var(--text-wall-label)] font-medium">{{ $label }}</span>
        </button>
    @endforeach
</div>
