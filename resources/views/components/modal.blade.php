@props([
    // A Livewire expression that closes it, used for the tap-outside.
    'dismiss' => null,
    // For dialogs whose open state lives in Alpine rather than in Livewire.
    'closeOn' => null,
    'label' => null,
    // 'base' sits over the page; 'over' sits above another dialog; 'top' is
    // for the PIN pad, which has to sit above everything.
    'layer' => 'base',
    'width' => 'max-w-md',
])

@php
    // Written out in full so Tailwind's scanner can see them.
    $z = match ($layer) {
        'over' => 'z-[55]',
        'top' => 'z-[60]',
        default => 'z-50',
    };
@endphp

{{--
    One dialog shape for the whole app.

    Two things it gets right that hand-rolled ones kept getting wrong. The dim
    and the centring are the *same element*: as two, the full-screen centring
    layer covered the backdrop and "tap outside to close" silently did nothing.
    And it is measured against the **visual** viewport, not the layout one — on
    iOS the keyboard shrinks the visual viewport and leaves the layout viewport
    at its full height, so anything positioned against the latter puts its Save
    button behind the keyboard.
--}}
<div
    @if (filled($dismiss)) wire:click.self="{{ $dismiss }}" @endif
    @if (filled($closeOn)) x-on:click.self="{{ $closeOn }}" @endif
    class="modal-backdrop modal-viewport {{ $z }}"
    role="dialog"
    aria-modal="true"
    @if ($label) aria-label="{{ $label }}" @endif
>
    <div {{ $attributes->merge([
        'class' => 'flex max-h-full w-full '.$width
            .' flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-900',
    ]) }}>
        {{--
            A header and a footer that stay put while the middle scrolls.

            Without them the whole dialog is one scrolling block, and on a short
            viewport — a phone with the keyboard up, most of all — its title and
            its Save button scroll out of sight with nothing to say they are
            there. That is how "add a recipe" became impossible on a phone: the
            heading, the mode tabs and the field itself were all above the top
            of a panel nobody could tell was scrolled.

            Both are optional, so a dialog that fits needs neither.
        --}}
        {{-- Neither band gives way. Letting the header shrink to guarantee the
             footer sounded prudent and was worse: at a real keyboard-up height
             it squeezed and clipped the mode tabs, to rescue a viewport
             shorter than any phone actually has. --}}
        @if (isset($header))
            <div class="shrink-0">{{ $header }}</div>
        @endif

        <div class="pane-scroll min-h-0 flex-1 overflow-y-auto">
            {{ $slot }}
        </div>

        @if (isset($footer))
            <div class="shrink-0">{{ $footer }}</div>
        @endif
    </div>
</div>
