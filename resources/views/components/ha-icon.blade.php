@props(['domain'])

{{-- One drawing per kind of thing, so a wall of tiles is scannable before any
     of it is read. Inline SVG rather than an emoji: the Pi has no emoji font,
     and every device that does draws them differently. --}}
<x-icon
    :name="match ($domain) {
        'light' => 'light',
        'switch' => 'switch',
        'climate' => 'climate',
        'cover' => 'cover',
        'scene' => 'scene',
        'script' => 'script',
        default => 'device',
    }"
    {{ $attributes }}
/>
