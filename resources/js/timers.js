/**
 * Kitchen timers, as arithmetic.
 *
 * The server hands down the moment each timer goes off; everything the strip
 * shows is worked out from that against the browser's own clock. Nothing
 * here touches the DOM, so the part that is easy to get wrong — which timer
 * is shouting, whether it has already chimed, what happens when a paused one
 * is resumed — can be tested without a browser.
 */

/** How long a finished timer holds the full-width banner. */
export const BANNER_MS = 120_000;

/** How often the strip asks the server what it has missed. */
export const RESYNC_MS = 30_000;

/**
 * Seconds left on one timer.
 *
 * A paused timer carries its own remainder and does not move; a running one
 * is simply the distance to its end time. Never negative: "minus four
 * minutes" is not a thing a kitchen timer says.
 */
export function secondsLeft(timer, nowMs = Date.now()) {
    if (!timer) return 0;

    if (timer.paused) {
        return Math.max(0, Math.round(timer.remaining ?? 0));
    }

    if (!timer.endsAt) {
        return Math.max(0, Math.round(timer.remaining ?? 0));
    }

    return Math.max(0, Math.ceil((timer.endsAt - nowMs) / 1000));
}

export function hasFinished(timer, nowMs = Date.now()) {
    return !timer?.paused && secondsLeft(timer, nowMs) <= 0;
}

/**
 * m:ss, or h:mm:ss once there is an hour on it.
 *
 * Tabular figures elsewhere stop this shimmering; the shape is kept as short
 * as it can be because the strip is read in passing.
 */
export function formatted(seconds) {
    const total = Math.max(0, Math.round(seconds));
    const s = total % 60;
    const m = Math.floor(total / 60) % 60;
    const h = Math.floor(total / 3600);

    const pad = (n) => String(n).padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

/**
 * Which timers have just gone off and have not been announced.
 *
 * `rang` comes from the server, so a wall and a phone looking at the same
 * pasta do not both chime — whichever notices first tells the server, and
 * the other one's next sync says it is already handled.
 */
export function newlyFinished(timers, announced, nowMs = Date.now()) {
    return timers.filter(
        (timer) => hasFinished(timer, nowMs) && !timer.rang && !announced.has(timer.id),
    );
}

/**
 * The timer that gets the full-width banner, if any.
 *
 * The one that finished most recently, and only for two minutes — a timer
 * nobody was in the room for should not still be shouting at bedtime. It
 * stays in the strip until somebody dismisses it; only the banner gives up.
 *
 * `shownAt` is a map of timer id to the moment the banner went up, which is
 * the caller's to keep: it is about this screen, not about the timer.
 */
export function bannerFor(timers, shownAt, nowMs = Date.now()) {
    const shouting = timers
        .filter((timer) => hasFinished(timer, nowMs))
        .filter((timer) => {
            const since = shownAt.get(timer.id);

            return since === undefined || nowMs - since < BANNER_MS;
        });

    if (shouting.length === 0) return null;

    // The most recently finished one, which is the one somebody in the room
    // is reacting to.
    return shouting.reduce((latest, timer) =>
        (timer.endsAt ?? 0) > (latest.endsAt ?? 0) ? timer : latest,
    );
}

/**
 * Fold a fresh list from the server into what is on screen.
 *
 * The server's list is the truth about which timers exist and when they go
 * off; it is not the truth about whether this screen has already chimed for
 * one, which is why the announced set is kept separate and pruned here
 * rather than rebuilt.
 */
export function adopt(fresh, announced) {
    const alive = new Set(fresh.map((timer) => timer.id));

    for (const id of announced) {
        if (!alive.has(id)) announced.delete(id);
    }

    return fresh;
}
