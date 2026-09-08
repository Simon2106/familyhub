import { test } from 'node:test';
import assert from 'node:assert/strict';

import { isWithinWindow, minutesInZone, isDarkNow, minutesOfDay } from '../../resources/js/dark-mode.js';

const at = (h, m = 0) => new Date(2026, 8, 6, h, m);

test('a window that crosses midnight is dark on both sides of it', () => {
    // The default schedule: 21:00 -> 06:30.
    assert.equal(isDarkNow('21:00', '06:30', at(22)), true);
    assert.equal(isDarkNow('21:00', '06:30', at(2)), true);
    assert.equal(isDarkNow('21:00', '06:30', at(6, 29)), true);
});

test('a window that crosses midnight is light in the daytime', () => {
    assert.equal(isDarkNow('21:00', '06:30', at(6, 30)), false);
    assert.equal(isDarkNow('21:00', '06:30', at(12)), false);
    assert.equal(isDarkNow('21:00', '06:30', at(20, 59)), false);
});

test('the boundary is inclusive at the start and exclusive at the end', () => {
    assert.equal(isDarkNow('21:00', '06:30', at(21, 0)), true);
    assert.equal(isDarkNow('21:00', '06:30', at(6, 30)), false);
});

test('a same-day window works too', () => {
    // Someone who wants a dark afternoon rather than a dark night.
    assert.equal(isDarkNow('13:00', '17:00', at(15)), true);
    assert.equal(isDarkNow('13:00', '17:00', at(9)), false);
    assert.equal(isDarkNow('13:00', '17:00', at(23)), false);
});

test('an empty window is never dark', () => {
    assert.equal(isDarkNow('21:00', '21:00', at(21)), false);
});

test('a malformed schedule leaves the display light rather than blacking it out', () => {
    assert.equal(isDarkNow('', '06:30', at(22)), false);
    assert.equal(isDarkNow('nonsense', 'also nonsense', at(22)), false);
    assert.equal(minutesOfDay('bad'), null);
});

test('minutesOfDay converts correctly', () => {
    assert.equal(minutesOfDay('00:00'), 0);
    assert.equal(minutesOfDay('06:30'), 390);
    assert.equal(minutesOfDay('21:00'), 1260);
});

test('the time of day is read from the named zone, not the machine', () => {
    // 22:30 UTC is 23:30 in London during British Summer Time.
    const summerEvening = new Date('2026-07-01T22:30:00Z');

    assert.equal(minutesInZone('Europe/London', summerEvening), 23 * 60 + 30);
    assert.equal(minutesInZone('UTC', summerEvening), 22 * 60 + 30);
});

test('a nonsense zone falls back to the machine rather than throwing', () => {
    const at = new Date('2026-07-01T22:30:00Z');

    assert.equal(minutesInZone('Not/AZone', at), at.getHours() * 60 + at.getMinutes());
    assert.equal(minutesInZone(null, at), at.getHours() * 60 + at.getMinutes());
});

test('the overnight screen-off window is evaluated in the household zone', () => {
    // 22:15 UTC on a summer night is 23:15 in London — inside 23:00-06:30.
    const inside = new Date('2026-07-01T22:15:00Z');
    assert.equal(isWithinWindow('23:00', '06:30', inside, 'Europe/London'), true);

    // The same instant is 22:15 UTC, which is outside it.
    assert.equal(isWithinWindow('23:00', '06:30', inside, 'UTC'), false);
});

test('a wall whose clock is wrong still wakes in the morning', () => {
    const morning = new Date('2026-01-15T06:31:00Z');

    assert.equal(isWithinWindow('23:00', '06:30', morning, 'Europe/London'), false);
});

test('a zero-length window never blacks the screen', () => {
    assert.equal(isWithinWindow('23:00', '23:00', new Date(), 'Europe/London'), false);
});
