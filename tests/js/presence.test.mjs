import { strict as assert } from 'node:assert';
import test from 'node:test';

import { ASLEEP, AWAKE, SAVER, decide, trackClearing } from '../../resources/js/presence.js';

const sensors = (over = {}) => ({
    configured: true,
    readable: true,
    near: false,
    room: false,
    clearFor: 0,
    holdSeconds: 90,
    deepSleepMinutes: 10,
    ...over,
});

test('somebody at the wall wakes it to the face for the hour', () => {
    assert.equal(decide(sensors({ near: true, room: true })), AWAKE);
});

test('somebody in the room but not at the wall gets the ordinary screensaver', () => {
    assert.equal(decide(sensors({ room: true })), SAVER);
});

test('an empty room goes dark, but only after the wait', () => {
    assert.equal(decide(sensors({ clearFor: 60 })), SAVER);
    assert.equal(decide(sensors({ clearFor: 9 * 60 })), SAVER);
    assert.equal(decide(sensors({ clearFor: 11 * 60 })), ASLEEP);
});

test('a household that does not want a black screen never gets one', () => {
    assert.equal(decide(sensors({ clearFor: 99999, deepSleepMinutes: 0 })), SAVER);
});

test('the face is held for a minute and a half after they step away', () => {
    // Somebody who turned to the hob has not left the kitchen.
    const now = 1_000_000;

    assert.equal(decide(sensors(), { now, nearClearedAt: now - 10_000 }), AWAKE);
    assert.equal(decide(sensors(), { now, nearClearedAt: now - 80_000 }), AWAKE);
    assert.equal(decide(sensors(), { now, nearClearedAt: now - 95_000 }), SAVER);
});

test('at night approaching shows the dim clock rather than the kitchen', () => {
    assert.equal(decide(sensors({ near: true, room: true }), { night: true }), SAVER);

    // Unless a hand actually lands on it.
    assert.equal(
        decide(sensors({ near: true }), { night: true, touchedAt: Date.now(), idleMs: 600_000 }),
        AWAKE,
    );
});

test('touch beats every sensor in the house', () => {
    const now = 1_000_000;

    assert.equal(
        decide(sensors({ clearFor: 99999 }), { now, touchedAt: now - 5_000, idleMs: 600_000 }),
        AWAKE,
    );

    // And stops beating them once the ordinary idle timer would have run out.
    assert.equal(
        decide(sensors({ clearFor: 99999 }), { now, touchedAt: now - 700_000, idleMs: 600_000 }),
        ASLEEP,
    );
});

test('with nothing set up the wall behaves exactly as it did before', () => {
    assert.equal(decide({ configured: false }, { idle: false }), AWAKE);
    assert.equal(decide({ configured: false }, { idle: true }), SAVER);
});

test('home assistant being unreachable is not an empty room', () => {
    // A Pi that is off must not black out the kitchen screen.
    assert.equal(decide(sensors({ readable: false, clearFor: 99999 }), { idle: false }), AWAKE);
});

test('the moment the near zone cleared is remembered, and only that moment', () => {
    const now = 500;

    assert.equal(trackClearing(true, false, now), now, 'It just cleared.');
    assert.equal(trackClearing(false, false, now), undefined, 'Already clear — leave it alone.');
    assert.equal(trackClearing(true, true, now), null, 'Occupied again.');
});
