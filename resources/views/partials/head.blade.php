<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, user-scalable=no">
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>{{ $title ?? config('app.name') }}</title>

{{-- Installed-PWA behaviour on iOS: no Safari chrome, dark status bar. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<meta name="theme-color" content="#0f172a">

{{-- Dates and phone numbers must not become tappable links on the wall. --}}
<meta name="format-detection" content="telephone=no,date=no,address=no,email=no">

{{-- use-credentials so the manifest fetch carries the pairing cookie; without
     it the browser fetches anonymously and the display manifest 403s. --}}
<link rel="manifest" href="{{ $manifestUrl ?? route('pwa.manifest') }}"
      @if ($manifestCredentials ?? false) crossorigin="use-credentials" @endif>
<link rel="apple-touch-icon" href="{{ asset('icons/icon-180.png') }}">
<link rel="icon" href="{{ asset('icons/icon-192.png') }}">

{{-- The deployed build id, so the display can notice a deploy and reload. --}}
<meta
    name="build-version"
    content="{{ \App\Support\BuildVersion::current() }}"
    data-endpoint="{{ route('version') }}"
    data-poll-ms="60000"
    data-idle-ms="30000"
    data-daily-at="03:45"
>

{{-- Realtime, when it is switched on. Absent entirely otherwise, so an
     install without Reverb never loads a websocket client it cannot use. --}}
@if (config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key')))
    <meta
        name="reverb-key"
        content="{{ config('broadcasting.connections.reverb.key') }}"
        data-host="{{ config('broadcasting.connections.reverb.options.host') }}"
        data-port="{{ config('broadcasting.connections.reverb.options.port') }}"
        data-scheme="{{ config('broadcasting.connections.reverb.options.scheme') }}"
        data-path="{{ config('reverb.servers.reverb.path') ? '/'.trim(config('reverb.servers.reverb.path'), '/') : '' }}"
    >
@endif

@vite(['resources/css/app.css', 'resources/js/app.js'])
