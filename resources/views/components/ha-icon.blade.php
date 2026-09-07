@props(['domain'])

{{-- One glyph per kind of thing, so a wall of tiles is scannable before any
     of it is read. --}}
<span {{ $attributes }}>{{ match ($domain) {
    'light' => '💡',
    'switch' => '🔌',
    'climate' => '🌡️',
    'cover' => '🪟',
    'scene' => '🎬',
    'script' => '▶️',
    default => '⚙️',
} }}</span>
