<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>{{ config('app.name') }} — not paired</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="dark">
        <div class="app-shell grid place-items-center bg-slate-950 px-8 text-center text-slate-100">
            <div class="max-w-md">
                <h1 class="text-2xl font-semibold">This screen isn't paired yet</h1>
                <p class="mt-2 text-slate-400">
                    Open the display URL once on this device to pair it. Run
                    <code class="rounded bg-slate-800 px-1.5 py-0.5 text-slate-200">php&nbsp;artisan&nbsp;familyhub:display-token</code>
                    to get it.
                </p>

                <p id="recovering" class="mt-8 hidden text-blue-400">Re-pairing from this device…</p>

                <a href="{{ route('login') }}"
                   class="touch-target mt-8 inline-flex items-center rounded-xl bg-blue-600 px-6 font-semibold text-white">
                    Sign in instead
                </a>
            </div>
        </div>

        {{-- If this device was paired before and only lost its cookie, it still
             has the token in localStorage and can let itself back in. --}}
        <script>
            (function () {
                try {
                    var token = window.localStorage.getItem('familyhub.display_token');
                    if (!token || new URL(window.location.href).searchParams.get('token')) return;

                    document.getElementById('recovering').classList.remove('hidden');
                    window.location.replace('{{ route('display') }}?token=' + encodeURIComponent(token));
                } catch (e) {
                    // No storage access; the on-screen instructions stand.
                }
            })();
        </script>
    </body>
</html>
