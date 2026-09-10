@props(['name', 'label' => null])

{{--
    One inline SVG for every glyph the app draws itself.

    Emoji were the obvious first answer and the wrong one: the Pi kiosk has no
    emoji font at all, so a wall of them is a wall of empty boxes, and every
    device that does have one draws them differently — the same forecast looked
    like three different forecasts on the wall, an iPad and a phone.

    Drawn at 24x24 in currentColor so a glyph takes the size and colour of
    whatever it sits in, and stroked to match the icons already in the app.

    Icons the household chose themselves — a chore's, a routine step's — are
    their data and are left alone; the fallbacks around them are drawn here.
--}}
@php
    $paths = match ($name) {
        /* ---------------------------- weather ---------------------------- */
        'sun' => '<circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2M12 19.5v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M2.5 12h2M19.5 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/>',
        'cloud' => '<path d="M7 18h10a4 4 0 0 0 .3-8A6 6 0 0 0 6 11.2 3.4 3.4 0 0 0 7 18z"/>',
        'sun-cloud' => '<path d="M8 15H6.8A3.3 3.3 0 0 1 7 8.4a5 5 0 0 1 9.4-.6"/><path d="M12.5 20h5a3.5 3.5 0 0 0 0-7h-.3a5 5 0 0 0-9.4 1.4A3 3 0 0 0 8.5 20z"/><path d="M12 2.6v1.5M4.6 5.6l1 1M2.6 13h1.5"/>',
        'fog' => '<path d="M7 14h10a4 4 0 0 0 .3-8A6 6 0 0 0 6 7.2 3.4 3.4 0 0 0 7 14z"/><path d="M4 18h16M6 21.5h12"/>',
        'drizzle' => '<path d="M7 15h10a4 4 0 0 0 .3-8A6 6 0 0 0 6 8.2 3.4 3.4 0 0 0 7 15z"/><path d="M9 18.5v1M13 18.5v1M17 18.5v1"/>',
        'rain' => '<path d="M7 14h10a4 4 0 0 0 .3-8A6 6 0 0 0 6 7.2 3.4 3.4 0 0 0 7 14z"/><path d="M8.5 17.5 7.5 21M12.5 17.5l-1 3.5M16.5 17.5l-1 3.5"/>',
        'snow' => '<path d="M7 13h10a4 4 0 0 0 .3-8A6 6 0 0 0 6 6.2 3.4 3.4 0 0 0 7 13z"/><path d="M9 17h.01M13 17h.01M17 17h.01M11 20.5h.01M15 20.5h.01"/>',
        'snow-showers' => '<path d="M7 13h10a4 4 0 0 0 .3-8A6 6 0 0 0 6 6.2 3.4 3.4 0 0 0 7 13z"/><path d="M9.5 16.5 8.5 20M13 17h.01M17 17h.01M15 20.5h.01"/>',
        'storm' => '<path d="M7 13h10a4 4 0 0 0 .3-8A6 6 0 0 0 6 6.2 3.4 3.4 0 0 0 7 13z"/><path d="m13 15-3 4h3.5l-1 3.5"/>',

        /* --------------------------- the house --------------------------- */
        'light' => '<path d="M9.5 18h5M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.3.2.5.6.5 1V16h6v-1.1c0-.4.2-.8.5-1A6 6 0 0 0 12 3z"/>',
        'switch' => '<path d="M9 2v6M15 2v6"/><path d="M6 8h12v3a6 6 0 0 1-12 0z"/><path d="M12 17v5"/>',
        'climate' => '<path d="M14 14.8V5a2 2 0 1 0-4 0v9.8a4 4 0 1 0 4 0z"/><path d="M12 17.5h.01"/>',
        'cover' => '<rect x="3.5" y="3.5" width="17" height="17" rx="2"/><path d="M3.5 8.5h17M3.5 13h17M12 13v7.5"/>',
        'scene' => '<path d="m12 3 2 4.6 4.6 2-4.6 2-2 4.6-2-4.6-4.6-2 4.6-2z"/><path d="M18 16.5l.8 1.7 1.7.8-1.7.8-.8 1.7-.8-1.7-1.7-.8 1.7-.8z"/>',
        'script' => '<circle cx="12" cy="12" r="9"/><path d="M10 8.5v7l6-3.5z"/>',
        'device' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 8.5 19.4a1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 14.2H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 8.5a1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',
        'music' => '<path d="M9 18V5l11-2v13"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="16" r="2.5"/>',

        /* ---------------------------- opinions --------------------------- */
        'thumb-up' => '<path d="M7 10.5V20H4.5a1 1 0 0 1-1-1v-7.5a1 1 0 0 1 1-1z"/><path d="M7 10.5 11 3a2.2 2.2 0 0 1 2.2 2.2V9h5.1a2 2 0 0 1 2 2.4l-1.3 6A2 2 0 0 1 17 19H7"/>',
        'thumb-down' => '<path d="M7 13.5V4H4.5a1 1 0 0 0-1 1v7.5a1 1 0 0 0 1 1z"/><path d="M7 13.5 11 21a2.2 2.2 0 0 0 2.2-2.2V15h5.1a2 2 0 0 0 2-2.4l-1.3-6A2 2 0 0 0 17 5H7"/>',
        /* Two arrows rather than a shrug: the drawing has to say "some up,
           some down" at sixteen pixels, and a hedged thumb says nothing. */
        'thumbs-split' => '<path d="M8 20.5V4M8 4 4.5 7.5M8 4l3.5 3.5"/><path d="M16 3.5V20M16 20l-3.5-3.5M16 20l3.5-3.5"/>',

        /* ----------------------------- moments --------------------------- */
        'celebrate' => '<path d="m3 21 5.5-13L16 15.5z"/><path d="M14 3.5v2M18.5 6l1.4-1.4M17 10.5h2.5M19.5 13.5 21 15"/>',
        'gift' => '<rect x="3.5" y="9" width="17" height="11.5" rx="1.5"/><path d="M3 9h18M12 9v11.5"/><path d="M12 9S9.5 3.5 7.2 4.5 9 9 12 9zM12 9s2.5-5.5 4.8-4.5S15 9 12 9z"/>',
        'ticked' => '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/><path d="m8 12.2 2.8 2.8L16.5 9.3"/>',
        'unticked' => '<rect x="3.5" y="3.5" width="17" height="17" rx="3"/>',
        'check' => '<path d="m5 12.5 5 5L19 7"/>',
        /* The same fork and knife the Meals tab uses, so one idea has one
           drawing wherever it turns up. */
        'cutlery' => '<path d="M6 3v7a2.5 2.5 0 0 0 5 0V3M8.5 10v11"/><path d="M17 3c-1.5 2-2 4-2 6s.5 3 2 3 2-1 2-3-.5-4-2-6zm0 9v9"/>',
        /* ------------------------------ bins ----------------------------- */
        'bin' => '<path d="M4.5 7h15M9.5 7V4.5h5V7"/><path d="M6 7l1 12.5a1.5 1.5 0 0 0 1.5 1.4h7a1.5 1.5 0 0 0 1.5-1.4L18 7"/><path d="M10 11v6M14 11v6"/>',
        'recycle' => '<path d="m8.5 4.5 2-3.2a1.8 1.8 0 0 1 3 0l1.7 2.8"/><path d="M18.8 9.6 20.6 13a1.8 1.8 0 0 1-1.5 2.7h-3.3"/><path d="M8.2 15.7H4.9A1.8 1.8 0 0 1 3.4 13l1.7-3"/><path d="m6.6 8.4 1.6 1.3-2 .6zM17.4 12.6l-2 .3 1.1-1.7zM11 18.2l1.6-1.3.2 2.1z"/>',
        'box' => '<path d="M3.5 7.5 12 4l8.5 3.5v9L12 20l-8.5-3.5z"/><path d="M3.5 7.5 12 11l8.5-3.5M12 11v9"/>',
        'leaf' => '<path d="M5 19c-1.5-6 2-11.5 9.5-12.5C18 6 20 5.5 20 5.5s.5 12-8 13c-3.5.4-5.5-1-7 .5"/><path d="M6 18c3-4.5 7-7 11-8.5"/>',
        'apple' => '<path d="M12 8.5c-1-1.5-3-2.2-4.6-1.3C5.4 8.3 4.5 11 5.5 14.4S9 21 11 20.2c.6-.3 1.4-.3 2 0 2 .8 4.5-2.4 5.5-5.8s.1-6.1-1.9-7.2c-1.6-.9-3.6-.2-4.6 1.3z"/><path d="M12 8.5V6a2.5 2.5 0 0 1 2.5-2.5"/>',
        'plug' => '<path d="M9 3v6M15 3v6"/><path d="M6.5 9h11v2.5a5.5 5.5 0 0 1-11 0z"/><path d="M12 17v4"/>',

        'star' => '<path d="m12 3.5 2.6 5.3 5.9.9-4.3 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/>',
        'undo' => '<path d="M4 9h11a5 5 0 0 1 0 10h-4"/><path d="M8 5 4 9l4 4"/>',
        'pencil' => '<path d="M4 20h4L19.5 8.5a2.1 2.1 0 0 0-3-3L5 17z"/><path d="m14.5 6.5 3 3"/>',
        'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',

        default => '<circle cx="12" cy="12" r="8.5"/>',
    };
@endphp

<svg {{ $attributes->merge(['class' => 'size-6']) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
     stroke-linecap="round" stroke-linejoin="round"
     @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>
    {!! $paths !!}
</svg>
