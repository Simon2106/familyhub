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

// Completed to-dos stay findable under "Done" for the household's retention
// window, then go. Runs in the small hours so nobody watches items vanish.
/*
| Switch groups that turn themselves on and off.
|
| Every minute, because a schedule somebody edits at teatime has to take effect
| this evening — and because a missed minute is caught by the next one rather
| than lost.
*/
Schedule::command('familyhub:switch-schedules')
    ->everyMinute()
    ->withoutOverlapping();

/*
| Notifications.
|
| Every minute, because an event reminder is only useful at the minute it is
| due. Everything else in here is guarded by its own hour, so the cost of the
| frequency is one cheap query most of the time.
*/
Schedule::command('familyhub:notify')
    ->everyMinute()
    ->withoutOverlapping();

/*
| The morning's catch-up: anything nobody was told at the time, by email.
| After the quiet hours end, so a night's worth arrives in one message rather
| than trickling in from six o'clock.
*/
Schedule::command('familyhub:notice-digest')
    ->dailyAt('07:30')
    ->withoutOverlapping();

/*
| Repeating events, expanded into the days they actually happen on.
|
| The sync writes these as it goes; this is only about time passing — a series
| expanded eighteen months out in January is a month shorter every month.
*/
Schedule::command('familyhub:rebuild-occurrences')
    ->dailyAt('03:10')
    ->withoutOverlapping();

/*
| Subscribed calendars — a school's fixtures, a club's season.
|
| Every ten minutes, but each subscription carries its own interval and one
| that is not due is skipped without a request being made at all.
*/
Schedule::command('familyhub:sync-feeds')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
| Photographs, hourly.
|
| iCloud offers no push for a shared album — it is a public JSON endpoint and
| the only way to learn anything is to ask it — so "as soon as it is added"
| means "within the hour". Cheap: the first call returns a list of ids and
| only genuinely new ones are downloaded.
*/
Schedule::command('familyhub:sync-photos')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('familyhub:prune-done')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// The council's bin calendar. Early enough that a changed collection day is on
// the wall before anyone is awake to put the bins out.
Schedule::command('familyhub:sync-bins')
    ->dailyAt('04:20')
    ->withoutOverlapping();

// Bank holidays change when a jubilee is announced, so monthly is plenty.
// Falls back to the rules when GOV.UK cannot be reached.
Schedule::command('familyhub:sync-bank-holidays')
    ->monthlyOn(1, '04:40')
    ->withoutOverlapping();
