/**
 * The wall watching its own frame rate.
 *
 * The glass effects were specified with a budget: the Home face has to hold
 * fifty frames a second, and if it cannot, the displacement ring goes first
 * and the saturation second. That rule can be followed by hand on a machine
 * somebody is sitting at — but the wall is a Pi in a kitchen, and nobody is
 * going to stand in front of it with a profiler.
 *
 * So it measures itself, once, after it has settled, and gives up whatever
 * it has to. Once: a wall that keeps re-measuring is a wall spending frames
 * on measuring frames.
 */

/** Frames a second the Home face has to hold. */
export const BUDGET = 50;

/** How long to watch for. Long enough to average out a Livewire poll. */
export const SAMPLE_MS = 2000;

/** How long to let the page settle first — fonts, photographs, Livewire. */
export const SETTLE_MS = 4000;

/**
 * What to give up at a given frame rate.
 *
 * In the order the design brief set out, because the ring is the expensive
 * layer and the saturation is the one that costs the least to lose.
 */
export function remedyFor(fps, budget = BUDGET) {
    if (fps >= budget) return 'none';

    // Well under: the ring alone will not buy it back.
    if (fps < budget * 0.7) return 'plain';

    return 'lite';
}

/**
 * Watch for a while and report the average.
 *
 * Takes its own clock and scheduler so a test can run it without a browser.
 */
export function measure({ now, raf, duration = SAMPLE_MS }) {
    return new Promise((resolve) => {
        const started = now();
        let frames = 0;

        const tick = () => {
            frames++;

            const elapsed = now() - started;

            if (elapsed < duration) {
                raf(tick);
            } else {
                resolve(Math.round((frames / elapsed) * 1000));
            }
        };

        raf(tick);
    });
}

/**
 * Apply a remedy to the document.
 *
 * `lite` drops the refraction ring — the same thing the /admin switch does,
 * so a household that has already chosen it sees no change. `plain` drops
 * the saturation as well, which is the last thing worth losing before the
 * glass stops being glass.
 */
export function apply(remedy, root) {
    if (remedy === 'lite' || remedy === 'plain') root.classList.add('lite-glass');
    if (remedy === 'plain') root.classList.add('plain-glass');

    return remedy;
}

/**
 * The whole thing: settle, measure, remedy.
 *
 * Returns what it measured and what it did, which is what the wall logs so
 * there is an answer to "why does the kitchen screen look flatter than the
 * style guide".
 */
export async function watchOnce({ root, now, raf, wait, budget = BUDGET, settle = SETTLE_MS }) {
    await wait(settle);

    const fps = await measure({ now, raf });
    const remedy = apply(remedyFor(fps, budget), root);

    return { fps, remedy };
}
