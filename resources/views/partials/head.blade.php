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

<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="apple-touch-icon" href="{{ asset('icons/icon-180.png') }}">
<link rel="icon" href="{{ asset('icons/icon-192.png') }}">

@vite(['resources/css/app.css', 'resources/js/app.js'])
