import assert from 'node:assert/strict';
import { test } from 'node:test';

import { createDragBoard } from '../../resources/js/dragboard.js';

/** A fake clock, so hold timing is deterministic rather than slept through. */
function harness(options = {}) {
    const timers = new Map();
    let next = 1;
    const drops = [];

    const board = createDragBoard({
        onDrop: (payload, target) => drops.push({ payload, target }),
        elementFromPoint: options.elementFromPoint ?? (() => null),
        setTimer: (fn) => {
            timers.set(next, fn);

            return next++;
        },
        clearTimer: (id) => timers.delete(id),
        ...options,
    });

    return {
        board,
        drops,
        hold: () => {
            for (const fn of [...timers.values()]) fn();
            timers.clear();
        },
        pending: () => timers.size,
    };
}

/** A grid cell at every point, keyed by x for convenience. */
const cellAt = (key) => () => ({ closest: () => ({ dataset: { cell: key } }) });

test('nothing is picked up until the hold completes', () => {
    const { board } = harness();

    board.down({ clientX: 0, clientY: 0 }, { id: 1, from: 'mon|dinner' });

    assert.equal(board.isDragging, false);
});

test('the hold makes it a drag', () => {
    const { board, hold } = harness();

    board.down({ clientX: 0, clientY: 0 }, { id: 1, from: 'mon|dinner' });
    hold();

    assert.equal(board.isDragging, true);
});

test('a finger that wanders is scrolling, not dragging', () => {
    const { board, hold, pending } = harness();

    board.down({ clientX: 0, clientY: 0 }, { id: 1, from: 'mon|dinner' });
    board.move({ clientX: 0, clientY: 40 });

    assert.equal(pending(), 0, 'the pending hold should have been cancelled');

    hold();

    assert.equal(board.isDragging, false);
});

test('a small tremor during the hold is tolerated', () => {
    const { board, hold } = harness();

    board.down({ clientX: 0, clientY: 0 }, { id: 1, from: 'mon|dinner' });
    board.move({ clientX: 3, clientY: 4 });
    hold();

    assert.equal(board.isDragging, true);
});

test('dropping on another cell reports the move', () => {
    const { board, hold, drops } = harness({ elementFromPoint: cellAt('wed|dinner') });

    board.down({ clientX: 0, clientY: 0 }, { id: 7, from: 'mon|dinner' });
    hold();
    board.move({ clientX: 100, clientY: 10 });

    assert.equal(board.state.over, 'wed|dinner');

    board.up({ clientX: 100, clientY: 10 });

    assert.deepEqual(drops, [{ payload: { id: 7, from: 'mon|dinner' }, target: 'wed|dinner' }]);
});

test('dropping a meal back where it started changes nothing', () => {
    const { board, hold, drops } = harness({ elementFromPoint: cellAt('mon|dinner') });

    board.down({ clientX: 0, clientY: 0 }, { id: 7, from: 'mon|dinner' });
    hold();
    board.up({ clientX: 0, clientY: 0 });

    assert.deepEqual(drops, []);
});

test('a tap is not a drag', () => {
    const { board, drops } = harness({ elementFromPoint: cellAt('wed|dinner') });

    board.down({ clientX: 0, clientY: 0 }, { id: 7, from: 'mon|dinner' });
    board.up({ clientX: 0, clientY: 0 });

    assert.deepEqual(drops, []);
    assert.equal(board.isDragging, false);
});

test('releasing outside any cell drops nothing', () => {
    const { board, hold, drops } = harness({ elementFromPoint: () => null });

    board.down({ clientX: 0, clientY: 0 }, { id: 7, from: 'mon|dinner' });
    hold();
    board.move({ clientX: 500, clientY: 500 });
    board.up({ clientX: 500, clientY: 500 });

    assert.deepEqual(drops, []);
});
