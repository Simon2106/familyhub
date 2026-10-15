import { strict as assert } from 'node:assert';
import test from 'node:test';

import { BRIGHT, luminanceOf, regionFor, tintPanels } from '../../resources/js/glass.js';

/** A stub 2d context: the top half of the image white, the bottom black. */
const halfAndHalf = (size = 32) => ({
    getImageData: (x, y, w, h) => ({
        data: new Uint8ClampedArray(
            Array.from({ length: w * h * 4 }, (_, i) => {
                if (i % 4 === 3) return 255;
                const row = y + Math.floor(i / 4 / w);
                return row < size / 2 ? 255 : 0;
            }),
        ),
    }),
});

test('a bright region reads bright and a dark one reads dark', () => {
    const ctx = halfAndHalf();

    // Within a rounding error: this is an average of several hundred
    // floating-point divisions, not an integer.
    assert.ok(luminanceOf(ctx, { x: 0, y: 0, w: 1, h: 0.4 }) > 0.99);
    assert.ok(luminanceOf(ctx, { x: 0, y: 0.6, w: 1, h: 0.4 }) < 0.01);
});

test('a panel asks about the corner it is actually in', () => {
    // A sunset is bright at the top and black at the bottom; its average is
    // a lie about both ends, which is why this is a region and not a mean.
    const frame = { left: 0, top: 0, width: 1920, height: 1080 };

    assert.deepEqual(
        regionFor({ left: 960, top: 540, width: 480, height: 270 }, frame),
        { x: 0.5, y: 0.5, w: 0.25, h: 0.25 },
    );
});

test('a panel off the edge of the frame is clamped rather than sampled out of bounds', () => {
    const frame = { left: 0, top: 0, width: 1000, height: 1000 };

    const region = regionFor({ left: -200, top: -200, width: 100, height: 100 }, frame);

    assert.ok(region.x >= 0 && region.y >= 0);
});

test('only the panels over something bright are marked', () => {
    const ctx = halfAndHalf();
    const frame = { left: 0, top: 0, width: 100, height: 100 };

    const panel = (top) => ({
        dataset: {},
        getBoundingClientRect: () => ({ left: 0, top, width: 50, height: 20 }),
    });

    const high = panel(0);
    const low = panel(80);

    assert.equal(tintPanels([high, low], frame, ctx), 1);
    assert.equal(high.dataset.bright, '1');
    assert.equal(low.dataset.bright, '0');
});

test('a zero-sized panel is skipped rather than sampled', () => {
    // An x-show'd panel has no box, and asking the canvas about a zero-wide
    // region throws.
    const zero = { dataset: {}, getBoundingClientRect: () => ({ left: 0, top: 0, width: 0, height: 0 }) };

    assert.equal(tintPanels([zero], { left: 0, top: 0, width: 100, height: 100 }, halfAndHalf()), 0);
    assert.equal(zero.dataset.bright, undefined);
});

test('no readable canvas means no change at all', () => {
    // A cross-origin photograph taints the canvas. The default tint is a
    // better answer than a guess.
    const panel = { dataset: {}, getBoundingClientRect: () => ({ left: 0, top: 0, width: 10, height: 10 }) };

    assert.equal(tintPanels([panel], { left: 0, top: 0, width: 100, height: 100 }, null), 0);
    assert.equal(panel.dataset.bright, undefined);
});

test('the threshold is where a white shirt starts to win', () => {
    assert.ok(BRIGHT > 0.5 && BRIGHT < 0.8);
});
