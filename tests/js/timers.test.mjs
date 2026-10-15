import { strict as assert } from 'node:assert';
import test from 'node:test';

import {
    BANNER_MS,
    adopt,
    bannerFor,
    formatted,
    hasFinished,
    newlyFinished,
    secondsLeft,
} from '../../resources/js/timers.js';

const NOW = 1_760_000_000_000;

const running = (over) => ({ id: 1, name: 'Pasta', endsAt: NOW + over * 1000, paused: false, rang: false });

test('a running timer counts down from its end time, not from a stored number', () => {
    // Which is the whole reason a Chromium restart does not lose it.
    assert.equal(secondsLeft(running(300), NOW), 300);
    assert.equal(secondsLeft(running(300), NOW + 60_000), 240);
    assert.equal(secondsLeft(running(300), NOW + 299_500), 1);
});

test('it never counts past zero', () => {
    // "Minus four minutes" is not a thing a kitchen timer says.
    assert.equal(secondsLeft(running(-600), NOW), 0);
    assert.ok(hasFinished(running(-1), NOW));
    assert.ok(!hasFinished(running(1), NOW));
});

test('a paused timer sits still however long the page is left open', () => {
    const paused = { id: 2, paused: true, remaining: 360, endsAt: null };

    assert.equal(secondsLeft(paused, NOW), 360);
    assert.equal(secondsLeft(paused, NOW + 3_600_000), 360);
    assert.ok(!hasFinished(paused, NOW + 3_600_000), 'Paused is not finished.');
});

test('the clock is as short as it can be and still unambiguous', () => {
    assert.equal(formatted(0), '0:00');
    assert.equal(formatted(5), '0:05');
    assert.equal(formatted(65), '1:05');
    assert.equal(formatted(600), '10:00');
    assert.equal(formatted(3725), '1:02:05');
});

test('a timer is only announced once by this screen', () => {
    const announced = new Set();
    const timers = [running(-1)];

    assert.equal(newlyFinished(timers, announced, NOW).length, 1);

    announced.add(1);

    assert.equal(newlyFinished(timers, announced, NOW).length, 0);
});

test('a timer the server says has already rung is not announced again', () => {
    // The wall and a phone must not both chime for the same pasta.
    const timers = [{ ...running(-1), rang: true }];

    assert.equal(newlyFinished(timers, new Set(), NOW).length, 0);
});

test('the banner stands down on its own after two minutes', () => {
    // A timer nobody was in the room for should not still be shouting at
    // bedtime; it stays in the strip, but it stops taking the screen.
    const timers = [running(-5)];
    const shownAt = new Map();

    const first = bannerFor(timers, shownAt, NOW);

    assert.equal(first.id, 1);

    shownAt.set(1, NOW);

    assert.ok(bannerFor(timers, shownAt, NOW + BANNER_MS - 1000), 'Still up a minute later.');
    assert.equal(bannerFor(timers, shownAt, NOW + BANNER_MS + 1000), null);
});

test('the banner is the one that just went off, not the oldest on the list', () => {
    const older = { id: 1, name: 'Bread', endsAt: NOW - 60_000, paused: false, rang: false };
    const newer = { id: 2, name: 'Pasta', endsAt: NOW - 1_000, paused: false, rang: false };

    assert.equal(bannerFor([older, newer], new Map(), NOW).id, 2);
});

test('nothing running means no banner', () => {
    assert.equal(bannerFor([running(300)], new Map(), NOW), null);
    assert.equal(bannerFor([], new Map(), NOW), null);
});

test('adopting a fresh list forgets screens it has chimed for timers that are gone', () => {
    // Otherwise the set grows for as long as the kiosk is up, and an id
    // reused after a database reset would be silently muted.
    const announced = new Set([1, 2, 3]);

    adopt([{ id: 2 }], announced);

    assert.deepEqual([...announced], [2]);
});

test('a timer with neither an end time nor a remainder is simply over', () => {
    assert.equal(secondsLeft({ id: 9 }, NOW), 0);
    assert.equal(secondsLeft(null, NOW), 0);
});
