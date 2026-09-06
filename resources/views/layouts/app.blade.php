<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-dark-start="{{ config('familyhub.dark_mode.start') }}"
    data-dark-end="{{ config('familyhub.dark_mode.end') }}"
>
    <head>
        @include('partials.head')
    </head>
    <body class="lock-scroll no-select">
        {{ $slot }}
    </body>
</html>
