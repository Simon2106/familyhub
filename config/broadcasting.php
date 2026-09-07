<?php

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
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
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
