@php
    // Set by EnsureDisplayToken once the request is authorised.
    $displayToken = request()->attributes->get('display_token');
@endphp
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    data-dark-start="{{ config('familyhub.dark_mode.start') }}"
    data-dark-end="{{ config('familyhub.dark_mode.end') }}"
>
    <head>
        @include('partials.head', [
            // The installed PWA must relaunch at a URL that carries the token,
            // because iOS gives it a cookie jar of its own. A start_url of
            // plain /display would open unpaired every time.
            'manifestUrl' => $displayToken
                ? route('pwa.manifest.display', ['token' => $displayToken])
                : route('pwa.manifest'),
            'manifestCredentials' => true,
        ])

        {{-- The display keeps its own copy of the pairing token so it can
             re-authorise itself if this context ever loses its cookie. Note
             that iOS scopes localStorage per context too, so this recovers
             Safari-in-Safari and PWA-in-PWA, never one from the other. --}}
        <script>
            (function () {
                var token = @json($displayToken)
                    || new URL(window.location.href).searchParams.get('token');

                if (!token) return;

                try {
                    window.localStorage.setItem('familyhub.display_token', token);
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
