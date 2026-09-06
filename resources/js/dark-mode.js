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

export function isDarkNow(startText, endText, now = new Date()) {
    const start = minutesOfDay(startText);
    const end = minutesOfDay(endText);

    // A malformed schedule must not black out the wall display.
    if (start === null || end === null) return false;

    const current = now.getHours() * 60 + now.getMinutes();

    // start === end is a zero-length window, never a 24-hour one.
    if (start === end) return false;

    return start < end
        ? current >= start && current < end
        : current >= start || current < end;
}
