@php
    // Set by EnsureDisplayToken once the request is authorised.
    $displayToken = request()->attributes->get('display_token');
@endphp
<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    @php
        $household = \App\Models\Household::current();
        $dark = $household->darkMode();
        $screenOff = $household->screenOff();
    @endphp
    {{-- Seeded here so the schedule is right before Livewire boots; the wall
         component keeps them in step from then on. --}}
    data-dark-start="{{ $dark['start'] }}"
    data-dark-end="{{ $dark['end'] }}"
    @if ($screenOff['enabled'])
        data-screen-off-start="{{ $screenOff['start'] }}"
        data-screen-off-end="{{ $screenOff['end'] }}"
    @endif
    data-timezone="{{ $household->displayTimezone() }}"
    data-kiosk
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

        {{-- Caveat, preloaded only here.
             The notes board is on the wall's Home tab, so this is the one page
             that is certain to draw a note the moment it paints — and the one
             page nobody is standing in front of waiting for a reload. The
             phone downloads the same file, but on demand, when it first draws
             one. Latin only: latin-ext is for the occasional accented name
             and is not worth a blocking fetch on a kiosk boot. --}}
        <link rel="preload" as="font" type="font/woff2" crossorigin
              href="{{ asset('fonts/caveat-latin.woff2') }}">

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
