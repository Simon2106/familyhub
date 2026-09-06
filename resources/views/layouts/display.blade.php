<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-dark-start="{{ config('familyhub.dark_mode.start') }}"
    data-dark-end="{{ config('familyhub.dark_mode.end') }}"
>
    <head>
        @include('partials.head')

        {{-- The wall display keeps its own copy of the pairing token so it can
             re-authorise itself if Safari ever clears the cookie. --}}
        <script>
            (function () {
                var url = new URL(window.location.href);
                var token = url.searchParams.get('token');

                try {
                    if (token) {
                        window.localStorage.setItem('familyhub.display_token', token);
                    }
                } catch (e) {
                    // Private browsing — the cookie alone will have to do.
                }
            })();
        </script>
    </head>
    <body class="lock-scroll no-select bg-slate-100 dark:bg-slate-950">
        {{ $slot }}
    </body>
</html>
