import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createScreenOff } from '../../resources/js/screen-off.js';

/** A clock we can move by hand. */
function at(iso) {
    return new Date(iso);
}

function board(options = {}) {
    let clock = at('2026-01-15T20:00:00Z'); // 20:00 London, before the window
    const changes = [];

    const screen = createScreenOff({
        start: '23:00',
        end: '06:30',
        timeZone: 'Europe/London',
        wakeMs: 120000,
        now: () => clock,
        onChange: (asleep) => changes.push(asleep),
        ...options,
    });

    return {
        screen,
        changes,
        move: (iso) => {
            clock = at(iso);
        },
        forward: (ms) => {
            clock = new Date(clock.getTime() + ms);
        },
    };
}

test('the screen stays on outside the window', () => {
    const { screen } = board();

    screen.evaluate();

    assert.equal(screen.asleep, false);
});

test('it goes black inside the window', () => {
    const { screen, move } = board();

    move('2026-01-15T23:05:00Z');
    screen.evaluate();

    assert.equal(screen.asleep, true);
});

test('a touch wakes it', () => {
    const { screen, move } = board();

    move('2026-01-15T23:05:00Z');
    screen.evaluate();
    assert.equal(screen.asleep, true);

    const consumed = screen.touch();

    assert.equal(screen.asleep, false);
    assert.equal(consumed, true, 'The waking tap must not also press whatever was underneath.');
});

test('it settles back down once the wake runs out', () => {
    const { screen, move, forward } = board();

    move('2026-01-15T23:05:00Z');
    screen.evaluate();
    screen.touch();

    forward(60000);
    screen.evaluate();
    assert.equal(screen.asleep, false, 'A minute in, still awake.');

    forward(70000);
    screen.evaluate();
    assert.equal(screen.asleep, true);
});

test('touching an already-awake screen is not swallowed', () => {
    const { screen, move } = board();

    move('2026-01-15T23:05:00Z');
    screen.evaluate();
    screen.touch();

    // Awake now, so this tap belongs to whatever is under it.
    assert.equal(screen.touch(), false);
});

test('morning wakes it without anybody touching anything', () => {
    const { screen, move } = board();

    move('2026-01-15T23:05:00Z');
    screen.evaluate();
    assert.equal(screen.asleep, true);

    move('2026-01-16T06:31:00Z');
    screen.evaluate();

    assert.equal(screen.asleep, false);
});

test('a touch in the daytime buys nothing for the night', () => {
    const { screen, move } = board();

    screen.touch();

    move('2026-01-15T23:05:00Z');
    screen.evaluate();

    assert.equal(screen.asleep, true);
});

test('no schedule means the screen never turns itself off', () => {
    const { screen, move } = board({ start: null, end: null });

    move('2026-01-15T23:05:00Z');
    screen.evaluate();

    assert.equal(screen.asleep, false);
});

test('the window is read in the household zone', () => {
    // 23:05 UTC in January is 23:05 in London.
    const london = board({ timeZone: 'Europe/London' });
    london.move('2026-01-15T23:05:00Z');
    london.screen.evaluate();
    assert.equal(london.screen.asleep, true);

    // The same instant in New York is 18:05, nowhere near the window.
    const newYork = board({ timeZone: 'America/New_York' });
    newYork.move('2026-01-15T23:05:00Z');
    newYork.screen.evaluate();
    assert.equal(newYork.screen.asleep, false);
});

test('it only reports a change when the state actually changes', () => {
    const { screen, move, changes } = board();

    move('2026-01-15T23:05:00Z');
    screen.evaluate();
    screen.evaluate();
    screen.evaluate();

    assert.deepEqual(changes, [true], 'Redrawing a black screen every fifteen seconds is waste.');
});
