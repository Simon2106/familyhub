import { strict as assert } from 'node:assert';
import test from 'node:test';

import { DEFAULTS, driftAt, peakSpeed, roomFor } from '../../resources/js/drift.js';

const ROOM = { x: 1200, y: 600 };

test('the clock never leaves the room it was given', () => {
    // The whole point: a digit off the edge of a wall display is a digit
    // nobody can read and nobody can scroll to.
    for (let elapsed = 0; elapsed < 3_600_000; elapsed += 977) {
        const { x, y } = driftAt(elapsed, ROOM);

        assert.ok(x >= 0 && x <= ROOM.x, `x out of bounds at ${elapsed}: ${x}`);
        assert.ok(y >= 0 && y <= ROOM.y, `y out of bounds at ${elapsed}: ${y}`);
    }
});

test('it eases at the turns rather than bouncing', () => {
    // Speed is greatest in the middle of a sweep and falls to nothing at the
    // extreme; the easing is the shape of the curve, not something bolted on.
    const speedAt = (elapsed) => {
        const a = driftAt(elapsed, ROOM);
        const b = driftAt(elapsed + 100, ROOM);

        return Math.abs(b.x - a.x);
    };

    // x = (1 + sin(2*pi*t/period))/2, so the turn is a quarter period in.
    const atTurn = speedAt(DEFAULTS.periodX / 4);
    const atMiddle = speedAt(0);

    assert.ok(atTurn < atMiddle / 10, `turn ${atTurn} should be far slower than middle ${atMiddle}`);
});

test('it moves at a few pixels a second, not a few dozen', () => {
    // Slow enough that nobody watches it move.
    const speed = peakSpeed(ROOM.x, DEFAULTS.periodX);

    assert.ok(speed > 0.5, `too slow to matter: ${speed}`);
    assert.ok(speed < 8, `fast enough to notice: ${speed}`);
});

test('the two axes do not march in step', () => {
    // Equal periods would draw one diagonal line and burn that in instead.
    assert.notEqual(DEFAULTS.periodX, DEFAULTS.periodY);

    const start = driftAt(0, ROOM);
    const later = driftAt(DEFAULTS.periodX, ROOM);

    assert.ok(Math.abs(start.x - later.x) < 1, 'x returns after one period');
    assert.ok(Math.abs(start.y - later.y) > 1, 'y is somewhere else by then');
});

test('a clock bigger than the screen sits still rather than hiding', () => {
    const room = roomFor({ width: 800, height: 400 }, { width: 1000, height: 500 });

    assert.deepEqual(room, { x: 0, y: 0 });
    assert.deepEqual(driftAt(123_456, room), { x: 0, y: 0 });
});

test('the room is what is left over', () => {
    assert.deepEqual(roomFor({ width: 1920, height: 1080 }, { width: 700, height: 260 }), {
        x: 1220,
        y: 820,
    });
});
