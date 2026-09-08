import { isWithinWindow } from './dark-mode.js';

/**
 * The overnight blackout on the wall.
 *
 * The kiosk monitor cannot be power-cycled remotely, so "off" has to be a
 * state of the page: a full-black layer over everything, woken by a touch and
 * settling back down on its own.
 *
 * Deliberately plain and free of the framework. This is the one behaviour that
 * has to keep working when the network is down, Livewire has not booted, or
 * the socket has dropped — a wall that stays lit all night because a websocket
 * failed is a wall somebody unplugs.
 *
 * Kept free of DOM and timers so the decisions can be tested; the caller
 * supplies both.
 */
export function createScreenOff({
    start,
    end,
    timeZone = null,
    // How long a touch buys before it settles back down.
    wakeMs = 120000,
    now = () => new Date(),
    onChange = () => {},
} = {}) {
    let asleep = false;
    let wokeAt = null;

    /** Whether the schedule says the screen should be off at this instant. */
    function scheduled(at = now()) {
        return Boolean(start && end) && isWithinWindow(start, end, at, timeZone);
    }

    function evaluate() {
        const at = now();
        const inWindow = scheduled(at);

        if (!inWindow) {
            // Morning. Forget any waking; tonight starts fresh.
            wokeAt = null;

            return set(false);
        }

        if (wokeAt !== null && at - wokeAt < wakeMs) {
            return set(false);
        }

        // The wake has run out, so stop holding it open.
        wokeAt = null;

        return set(true);
    }

    function set(next) {
        if (next === asleep) return asleep;

        asleep = next;
        onChange(asleep);

        return asleep;
    }

    return {
        get asleep() {
            return asleep;
        },
        scheduled,
        evaluate,

        /**
         * A touch. Returns true when it was consumed by waking the screen, so
         * the caller knows to swallow it — nobody wants the tap that wakes the
         * wall to also tick off a chore.
         */
        touch() {
            if (!scheduled()) {
                wokeAt = null;

                return false;
            }

            const wasAsleep = asleep;
            wokeAt = now();

            evaluate();

            return wasAsleep;
        },
    };
}
