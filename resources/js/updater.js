/**
 * Keeps the wall display on the current build without anyone touching it.
 *
 * The iPad is mounted on a wall and never reloaded by hand, so a deploy would
 * otherwise leave it running last month's JavaScript against this month's
 * server indefinitely.
 *
 * Reloads are deliberately timid: never while someone is using the display, and
 * never so eagerly that a blip in connectivity restarts the page.
 */

export const DEFAULTS = {
    pollMs: 60_000,
    idleMs: 30_000,
    dailyReloadAt: '03:45',
    // A page that has just started should not immediately reload itself.
    minUptimeMs: 60_000,
};

/** Minutes since midnight, or null for an unparseable time. */
export function minutesOfDay(hhmm) {
    const [h, m] = String(hhmm).split(':').map(Number);

    return Number.isFinite(h) && Number.isFinite(m) ? h * 60 + m : null;
}

/**
 * Has the daily reload time been crossed between two instants?
 *
 * Comparing "is it 03:45 now" would miss the moment on a slow tick and fire
 * repeatedly on a fast one; comparing a crossing does neither.
 */
export function crossedDailyTime(previous, now, hhmm) {
    const target = minutesOfDay(hhmm);

    if (target === null) return false;

    const at = (d) => d.getHours() * 60 + d.getMinutes();
    const before = at(previous);
    const after = at(now);

    // Wrapped past midnight since the last check.
    if (after < before) return before < target || after >= target;

    return before < target && after >= target;
}

export function createUpdater(options = {}) {
    const config = { ...DEFAULTS, ...options };

    const state = {
        version: config.version ?? null,
        lastInteraction: config.now?.() ?? Date.now(),
        startedAt: config.now?.() ?? Date.now(),
        lastTick: config.date?.() ?? new Date(),
        pendingReload: false,
    };

    const now = config.now ?? (() => Date.now());
    const date = config.date ?? (() => new Date());
    const reload = config.reload ?? (() => window.location.reload());
    const fetchVersion =
        config.fetchVersion ??
        (async () => {
            const response = await fetch(config.endpoint ?? '/version', {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) throw new Error(`version endpoint returned ${response.status}`);

            return (await response.json()).version;
        });

    function touched() {
        state.lastInteraction = now();
    }

    function isIdle() {
        return now() - state.lastInteraction >= config.idleMs;
    }

    function settled() {
        return now() - state.startedAt >= config.minUptimeMs;
    }

    /** Reload now if nobody is using the display, otherwise wait for them to stop. */
    function reloadWhenIdle() {
        state.pendingReload = true;

        if (isIdle() && settled()) {
            // Cleared before reloading: a reload that is blocked or deferred
            // must not queue up another on every subsequent tick.
            state.pendingReload = false;
            reload();

            return true;
        }

        return false;
    }

    async function checkVersion() {
        let latest;

        try {
            latest = await fetchVersion();
        } catch {
            // A missed poll is not a reason to do anything; try again next tick.
            return false;
        }

        if (!latest) return false;

        if (state.version === null) {
            state.version = latest;

            return false;
        }

        if (latest !== state.version) {
            state.version = latest;

            return reloadWhenIdle();
        }

        return false;
    }

    /** Runs on every poll: version check, daily reload, and any deferred reload. */
    async function tick() {
        const currentDate = date();

        if (crossedDailyTime(state.lastTick, currentDate, config.dailyReloadAt)) {
            state.pendingReload = true;
        }

        state.lastTick = currentDate;

        await checkVersion();

        // Someone was mid-tap when we found an update; take the next quiet moment.
        if (state.pendingReload && isIdle() && settled()) {
            state.pendingReload = false;
            reload();

            return true;
        }

        return false;
    }

    return { tick, touched, checkVersion, reloadWhenIdle, isIdle, state, config };
}
