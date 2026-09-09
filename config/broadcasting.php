<?php

/*
| Where PHP reaches Reverb, as opposed to where a browser does.
|
| These are two different journeys to the same process and they were sharing
| one address, which is what broke it. A browser goes the long way round:
| https://hub.thewills.uk/reverb, through nginx, which proxies /reverb to
| Reverb. PHP is already on the box, so it goes straight to 127.0.0.1:8080 —
| and it has to, because nginx only proxies /reverb, while the server-side
| client posts to /apps/{id}/events. Sent to the public host that lands on
| Laravel, which answers a 404 page, and the Pusher client reports
| "Pusher error: <!DOCTYPE html>".
*/
$serverHost = env('REVERB_SERVER_HOST', '127.0.0.1');

// 0.0.0.0 is an address to listen on, never one to dial.
$serverHost = $serverHost === '0.0.0.0' ? '127.0.0.1' : $serverHost;

/*
| Reverb mounts every route under this prefix, the API endpoints included, so
| PHP needs it too even when it is not going through nginx. It needs a leading
| slash: the Pusher client concatenates it straight onto host:port. Reverb
| strips the prefix again before checking the signature, which is why the
| client may sign the unprefixed path and still be believed.
*/
$serverPath = trim((string) env('REVERB_SERVER_PATH', ''), '/');
$serverPath = $serverPath === '' ? '' : '/'.$serverPath;

return [

    /*
    | Reverb, or nothing at all.
    |
    | The wall polls Home Assistant's state either way; broadcasting is what
    | turns a three-second wait into an immediate one when a light is switched
    | from Alexa or a physical switch. Left as `null` the app is exactly as it
    | was — which is the point, because Reverb is a second process to keep
    | alive and not every install will want one.
    */
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            /*
             * The server-side client only. What the browser connects to is
             * baked into the bundle from VITE_REVERB_* and is untouched by
             * this — it still goes to REVERB_HOST over TLS.
             */
            'options' => [
                'host' => $serverHost,
                'port' => (int) env('REVERB_SERVER_PORT', 8080),
                // Plain http on the loopback: there is nothing between the two
                // processes to protect it from, and Reverb serves TLS only if
                // it has been given a certificate.
                'scheme' => 'http',
                'useTLS' => false,
                'path' => $serverPath,
            ],
            /*
             * And what the browser connects to, which is the other journey:
             * out to the public hostname over TLS and back in through nginx.
             * Kept here because the page has to render it into a meta tag, and
             * kept apart from `options` because sharing one address is what
             * broke this in the first place.
             */
            'browser' => [
                'host' => env('REVERB_HOST'),
                'port' => (int) env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'path' => $serverPath,
            ],

            'client_options' => [
                // Long enough to survive a busy Pi, short enough that a
                // broadcast never holds up the listener's read loop.
                'timeout' => 5,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
