@props(['member' => null, 'slug' => null, 'name' => '', 'colour' => '#8B5CF6', 'size' => 72])

@php
    // The artwork where there is some, the initial on a coloured disc where
    // there is not. Served from the webp the build command writes, never
    // the megabyte original — this is a Pi on a kitchen wall.
    $slug ??= \Illuminate\Support\Str::slug($name);
    $small = public_path("images/avatars/{$slug}-144.webp");
    $large = public_path("images/avatars/{$slug}-288.webp");
    $has = is_file($small);
@endphp

@if ($has)
    <img
        src="{{ asset("images/avatars/{$slug}-144.webp") }}"
        @if (is_file($large))
            srcset="{{ asset("images/avatars/{$slug}-144.webp") }} 1x, {{ asset("images/avatars/{$slug}-288.webp") }} 2x"
        @endif
        alt=""
        width="{{ $size }}" height="{{ $size }}"
        {{ $attributes->merge(['class' => 'shrink-0 rounded-full object-cover']) }}
        style="width: {{ $size }}px; height: {{ $size }}px; background-color: {{ $colour }}33;"
    >
@else
    <span
        {{ $attributes->merge(['class' => 'grid shrink-0 place-items-center rounded-full font-semibold text-white']) }}
        style="width: {{ $size }}px; height: {{ $size }}px; background-color: {{ $colour }}; font-size: {{ round($size * 0.4) }}px;"
        aria-hidden="true"
    >{{ \Illuminate\Support\Str::of($name)->trim()->substr(0, 1)->upper() }}</span>
@endif
