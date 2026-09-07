<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Household
    |--------------------------------------------------------------------------
    | FamilyHub is deliberately single-tenant. These values seed the one
    | household row and its members on first boot.
    */

    'household_name' => env('FAMILYHUB_HOUSEHOLD_NAME', 'Our Family'),

    /*
    | The household's wall-clock timezone. Everything is STORED in UTC
    | (config('app.timezone')) so DST never makes a stored time ambiguous;
    | this is the zone events are displayed in and, crucially, the zone whose
    | midnight decides which day an event belongs to.
    */
    'timezone' => env('FAMILYHUB_TIMEZONE', 'Europe/London'),

    'seed' => [
        // "Name|email|password" — multiple separated by ";"
        'users' => env('FAMILYHUB_SEED_USERS', ''),
        // "Name|#hexcolour|adult\child|pin" — multiple separated by ";"
        'members' => env('FAMILYHUB_SEED_MEMBERS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wall display
    |--------------------------------------------------------------------------
    | The wall-mounted iPad reaches /display?token=... once; the token is then
    | kept in localStorage so the device is never prompted to log in.
    */

    'display' => [
        'token' => env('FAMILYHUB_DISPLAY_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dark mode schedule
    |--------------------------------------------------------------------------
    | Applied client-side so the wall display dims on time without a round trip.
    | 24h local time; a window that crosses midnight is expected.
    */

    'dark_mode' => [
        'start' => env('FAMILYHUB_DARK_START', '21:00'),
        'end' => env('FAMILYHUB_DARK_END', '06:30'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idle photo screensaver
    |--------------------------------------------------------------------------
    */

    'screensaver' => [
        'idle_minutes' => (int) env('FAMILYHUB_IDLE_MINUTES', 10), // 0 disables
        'interval_seconds' => (int) env('FAMILYHUB_PHOTO_INTERVAL', 30),
    ],

    'photos' => [
        'disk' => env('FAMILYHUB_PHOTOS_DISK', 'public'),
        'path' => env('FAMILYHUB_PHOTOS_PATH', 'photos'),
    ],

    /*
    |--------------------------------------------------------------------------
    | To-dos
    |--------------------------------------------------------------------------
    | How long a completed to-do stays findable under "Done" before the nightly
    | cleanup removes it. Overridable per household in /admin.
    */

    'todos' => [
        // How early a dated to-do starts appearing on the wall.
        'lead_days' => (int) env('FAMILYHUB_TODO_LEAD_DAYS', 7),

        'done_retention_days' => (int) env('FAMILYHUB_DONE_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | CalDAV (Apple iCloud)
    |--------------------------------------------------------------------------
    | Apple ID and app-specific password are NOT configured here — they are
    | entered per account in /admin and stored encrypted. Only non-secret
    | transport settings live in config.
    */

    'caldav' => [
        'icloud_url' => env('ICLOUD_CALDAV_URL', 'https://caldav.icloud.com'),
        'timeout' => (int) env('CALDAV_TIMEOUT', 30),
        'user_agent' => 'FamilyHub/1.0 (CalDAV)',

        // How far either side of today a fallback calendar-query asks for.
        // sync-collection is used instead wherever the server supports it.
        'window_days_back' => (int) env('CALDAV_WINDOW_BACK', 90),
        'window_days_forward' => (int) env('CALDAV_WINDOW_FORWARD', 400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Later phases
    |--------------------------------------------------------------------------
    | Declared here so config:cache covers them from the start.
    */

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),

        // A term calendar can hold thirty items, and thinking comes out of the
        // same budget as the answer, so the ceiling has to cover both.
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 32000),

        // Extraction is transcription, not reasoning: find the dates already
        // written down. Low effort keeps thinking from eating the budget the
        // JSON needs. Raise it if a school's newsletters prove genuinely hard.
        // budget_tokens is rejected by current models; effort is the lever.
        'effort' => env('ANTHROPIC_EFFORT', 'low'),
    ],

    'postmark' => [
        'inbound_secret' => env('POSTMARK_INBOUND_SECRET'),
    ],

    /*
    | The address the household forwards things to. Mail delivered to the
    | inbound stream but addressed anywhere else is acknowledged and ignored.
    | Leave unset to accept anything the stream receives.
    */
    'inbound_address' => env('FAMILYHUB_INBOUND_ADDRESS'),

    'whatsapp' => [
        'enabled' => (bool) env('FAMILYHUB_WHATSAPP_ENABLED', false),
    ],

    // Phase 6: Home Assistant is the only smart-home driver. Zigbee switches
    // arrive via Zigbee2MQTT inside HA, and HA bridges the Alexa household.
    'homeassistant' => [
        'url' => env('HOMEASSISTANT_URL'),
        'token' => env('HOMEASSISTANT_TOKEN'),
    ],

    'weather' => [
        'latitude' => env('WEATHER_LATITUDE'),
        'longitude' => env('WEATHER_LONGITUDE'),
    ],

];
