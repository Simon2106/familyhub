import { test } from 'node:test';
import assert from 'node:assert/strict';

import { isDarkNow, minutesOfDay } from '../../resources/js/dark-mode.js';

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
