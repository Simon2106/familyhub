<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>{{ config('app.name') }} — not set up yet</title>
        @vite(['resources/css/app.css'])
    </head>
    <body class="dark">
        <div class="app-shell grid place-items-center bg-slate-950 px-8 text-center text-slate-100">
            <div class="max-w-lg">
                <h1 class="text-2xl font-semibold">Almost there</h1>
                <p class="mt-2 text-slate-400">
                    This device is paired correctly, but the database has no household in it yet.
                    Run this on the server, then reload:
                </p>
                <code class="mt-6 block overflow-x-auto rounded-xl bg-slate-800 px-4 py-3 text-left text-sm text-slate-200">{{ $fix }}</code>
            </div>
        </div>
    </body>
</html>
