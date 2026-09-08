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
        'class' => 'pane-scroll flex max-h-full w-full '.$width
            .' flex-col overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-slate-900',
    ]) }}>
        {{ $slot }}
    </div>
</div>
