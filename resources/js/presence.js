/**
 * What the wall does about somebody walking up to it.
 *
 * The sensor states arrive debounced from the server; this is the decision
 * made from them, and it is kept here rather than in the component because
 * "empty room, ten minutes, go dark" is a small state machine and a small
 * state machine in a Blade attribute is a bug waiting for a kitchen.
 *
 * Touch always wins. Somebody who has put a hand on the screen is somebody
 * the wall should stop guessing about.
 */

/** The wall is awake and showing the face for the hour. */
export const AWAKE = 'awake';

/** The ordinary screensaver: a clock, and what is on. */
export const SAVER = 'saver';

/** Black, with a faint clock. The room has been empty a while. */
export const ASLEEP = 'asleep';

/**
 * What the wall should be showing.
 *
 * @param {object} p the server's debounced read
 * @param {object} o the wall's own state: when it was last touched, when the
 *                   near zone last cleared, and whether it is night
 */
export function decide(p, o = {}) {
    const now = o.now ?? Date.now();

    // A hand on the screen beats every sensor in the house, for as long as
    // the ordinary idle timer would have lasted.
    if (o.touchedAt && now - o.touchedAt < (o.idleMs ?? 0)) {
        return AWAKE;
    }

    // Nothing set up, or Home Assistant unreachable: behave exactly as the
    // wall did before any of this existed.
    if (!p?.configured || !p?.readable) {
        return o.idle ? SAVER : AWAKE;
    }

    if (p.near) {
        // At night, approaching is not an invitation to light the kitchen
        // up with a chore board. The dim clock is the whole of it unless
        // somebody actually touches the screen.
        return o.night ? SAVER : AWAKE;
    }

    // The hold: the face stays for a minute and a half after they step
    // away, because somebody who turned to the hob has not left.
    if (o.nearClearedAt && now - o.nearClearedAt < (p.holdSeconds ?? 90) * 1000) {
        return o.night ? SAVER : AWAKE;
    }

    if (p.room) {
        return SAVER;
    }

    const deepAfter = (p.deepSleepMinutes ?? 10) * 60 * 1000;

    // Zero means never: a household that does not want a black screen in
    // the kitchen should not get one.
    if (deepAfter > 0 && (p.clearFor ?? 0) * 1000 >= deepAfter) {
        return ASLEEP;
    }

    return SAVER;
}

/**
 * Track when the near zone last went from occupied to clear.
 *
 * The hold is measured from that moment, and only the browser knows it —
 * the server reports the debounced state, not its history.
 */
export function trackClearing(was, isNear, now = Date.now()) {
    if (isNear) return null;

    return was ? now : undefined;
}
