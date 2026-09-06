<?php

use Illuminate\Support\Facades\Schedule;

/*
| iCloud has no push channel we can subscribe to, so calendars are polled.
| sync-collection makes each poll cheap: the server returns only what changed
| since the stored token, so a five-minute cadence is not expensive.
*/
Schedule::command('sync:calendars')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// A nightly full pass catches anything a token-based sync could have missed —
// a resync after a token reset, or an event edited outside the sync window.
Schedule::command('sync:calendars --force')
    ->dailyAt('03:30')
    ->withoutOverlapping();
