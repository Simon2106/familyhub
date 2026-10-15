@props([
    /** A sheet of glass, or a pill. */
    'as' => 'div',
    /** Pills and buttons are fully round; panels take the outer radius. */
    'shape' => 'panel',
    /** Drop the edge refraction on this one, whatever the household setting. */
    'plain' => false,
    /** Compress and brighten when pressed — for anything tappable. */
    'press' => false,
])

{{--
    One sheet of glass.

    Every panel on the wall is this: a blur, a tint that lets the photograph
    behind it show through and colour it, a one-pixel specular rim catching
    the light from the top left, and a soft shadow. The expensive part — the
    refraction at the very edge — lives in CSS and is dropped first when the
    Pi is struggling.

    Nesting: a panel sets --radius-inner for whatever sits 12px inside it, so
    the curves stay concentric rather than fighting. Use `rounded-[var(--radius-inner)]`
    on a tile rather than picking a number.
--}}

<{{ $as }} {{ $attributes->class([
    'glass',
    'glass--plain' => $plain,
    'press' => $press,
    'rounded-full' => $shape === 'pill',
]) }}>
    {{ $slot }}
</{{ $as }}>
