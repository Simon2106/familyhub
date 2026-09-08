/**
 * Dark mode by schedule.
 *
 * Kept free of DOM and timers so it can be tested directly. The window is
 * configurable and normally crosses midnight (21:00 -> 06:30), so the
 * comparison has to handle a wrapped range.
 */

export function minutesOfDay(hhmm) {
    const [h, m] = String(hhmm).split(':').map(Number);

    if (!Number.isFinite(h) || !Number.isFinite(m)) return null;

    return h * 60 + m;
}

/**
 * The time of day in a named zone, as minutes past midnight.
 *
 * Read from the zone rather than the machine, because the wall is a Raspberry
 * Pi whose clock configuration is one more thing that can quietly be wrong —
 * and a screen that blacks out an hour early is very obviously wrong.
 */
export function minutesInZone(timeZone, now = new Date()) {
    if (!timeZone) return now.getHours() * 60 + now.getMinutes();

    try {
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone,
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).formatToParts(now);

        const value = (type) => Number(parts.find((p) => p.type === type)?.value);
        const hour = value('hour');
        const minute = value('minute');

        if (!Number.isFinite(hour) || !Number.isFinite(minute)) {
            return now.getHours() * 60 + now.getMinutes();
        }

        // Midnight formats as 24 in some locales.
        return (hour % 24) * 60 + minute;
    } catch {
        return now.getHours() * 60 + now.getMinutes();
    }
}

/**
 * Is the clock inside a window that normally wraps past midnight?
 *
 * The shape both the dark-mode schedule and the overnight screen-off share.
 */
export function isWithinWindow(startText, endText, now = new Date(), timeZone = null) {
    const start = minutesOfDay(startText);
    const end = minutesOfDay(endText);

    // A malformed schedule must not black out the wall display.
    if (start === null || end === null) return false;

    // start === end is a zero-length window, never a 24-hour one.
    if (start === end) return false;

    const current = minutesInZone(timeZone, now);

    return start < end
        ? current >= start && current < end
        : current >= start || current < end;
}

export function isDarkNow(startText, endText, now = new Date(), timeZone = null) {
    return isWithinWindow(startText, endText, now, timeZone);
}
