/**
 * The slow wander of the screensaver clock.
 *
 * A wall-mounted panel showing the same bright numerals in the same place all
 * night is a panel with the time burnt into it, so the clock moves. It has to
 * move slowly enough that nobody watches it move, ease rather than bounce at
 * the turns, and never take a digit off the edge of the screen.
 *
 * Two sine waves of different periods do all three at once. Speed is greatest
 * in the middle and falls to nothing at each extreme — the easing is the shape
 * of the curve, not something bolted on — the path never repeats until the
 * periods coincide, and the amplitude is bounded by construction, so the clock
 * cannot leave the box however long the wall is left alone.
 */

export const DEFAULTS = {
    // Ten and thirteen minutes: long enough that the peak speed is a few
    // pixels a second, and coprime enough that the path fills the space
    // rather than retracing one line.
    periodX: 600_000,
    periodY: 780_000,
    // A quarter turn, so it does not start in a corner heading diagonally.
    phase: Math.PI / 2,
};

/**
 * Where the clock should be, this many milliseconds in.
 *
 * @param elapsed  ms since the screensaver started
 * @param room     how much space there is to move in: { x, y } in px
 */
export function driftAt(elapsed, room, options = {}) {
    const { periodX, periodY, phase } = { ...DEFAULTS, ...options };

    const across = (1 + Math.sin((2 * Math.PI * elapsed) / periodX)) / 2;
    const down = (1 + Math.sin((2 * Math.PI * elapsed) / periodY + phase)) / 2;

    return {
        x: Math.max(0, room.x) * across,
        y: Math.max(0, room.y) * down,
    };
}

/**
 * How much room there is to drift in.
 *
 * Negative when the clock is bigger than the screen, which is not a drift —
 * it is a clock that should sit still and be readable.
 */
export function roomFor(container, element) {
    return {
        x: Math.max(0, container.width - element.width),
        y: Math.max(0, container.height - element.height),
    };
}

/** Peak speed in px/s, for checking a period is slow enough to be ignorable. */
export function peakSpeed(room, period) {
    return (room * Math.PI) / (period / 1000);
}
