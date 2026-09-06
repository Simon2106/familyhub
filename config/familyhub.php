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
    | Later phases
    |--------------------------------------------------------------------------
    | Declared here so config:cache covers them from the start.
    */

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 8192),
    ],

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
