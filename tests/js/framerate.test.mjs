import { strict as assert } from 'node:assert';
import test from 'node:test';

import { BUDGET, apply, measure, remedyFor, watchOnce } from '../../resources/js/framerate.js';

test('a wall that is keeping up gives nothing up', () => {
    assert.equal(remedyFor(60), 'none');
    assert.equal(remedyFor(BUDGET), 'none');
});

test('a wall just under the budget softens the blur first', () => {
    // 28px to 18px is the cheapest ten pixels on the screen, and at 18 it
    // still reads as glass. The design brief puts it first for that reason.
    assert.equal(remedyFor(BUDGET - 1), 'soft');
    assert.equal(remedyFor(45), 'soft');
});

test('further under, the refraction ring goes as well', () => {
    assert.equal(remedyFor(40), 'lite');
    assert.equal(remedyFor(32), 'lite');
});

test('a wall well under loses the saturation too', () => {
    // Below about sixty per cent of the budget nothing short of flat
    // surfaces will buy it back, and half a remedy is a wall still
    // dropping frames.
    assert.equal(remedyFor(20), 'plain');
    assert.equal(remedyFor(10), 'plain');
});

test('the remedies are cumulative on the document', () => {
    const classes = new Set();
    const root = { classList: { add: (c) => classes.add(c) } };

    apply('soft', root);
    assert.deepEqual([...classes], ['soft-glass']);

    classes.clear();
    apply('lite', root);
    assert.deepEqual([...classes].sort(), ['lite-glass', 'soft-glass']);

    classes.clear();
    apply('plain', root);
    assert.deepEqual([...classes].sort(), ['lite-glass', 'plain-glass', 'soft-glass']);

    classes.clear();
    apply('none', root);
    assert.deepEqual([...classes], [], 'A wall that is fine is left alone.');
});

test('it measures what the frames actually were', async () => {
    // A fake clock running at exactly 30fps.
    let clock = 0;
    const now = () => clock;
    const raf = (fn) => {
        clock += 1000 / 30;
        queueMicrotask(fn);
    };

    assert.equal(await measure({ now, raf, duration: 1000 }), 30);
});

test('it settles before it measures, and reports both numbers', async () => {
    // Measuring while the fonts are still swapping and Livewire is booting
    // would condemn a wall for its first two seconds.
    let waited = 0;
    let clock = 0;

    const classes = new Set();

    const result = await watchOnce({
        root: { classList: { add: (c) => classes.add(c) } },
        now: () => clock,
        raf: (fn) => {
            clock += 1000 / 45; // a struggling wall
            queueMicrotask(fn);
        },
        wait: async (ms) => {
            waited = ms;
        },
    });

    assert.ok(waited >= 1000, 'It waited for the page to settle.');
    assert.equal(result.fps, 45);
    assert.equal(result.remedy, 'soft');
    assert.deepEqual([...classes], ['soft-glass']);
});
