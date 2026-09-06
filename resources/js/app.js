/**
 * FamilyHub front-end shell.
 *
 * Livewire ships and starts Alpine, so nothing here may import or start Alpine
 * itself. Everything below is plain browser code that has to keep working while
 * the wall display sits untouched for weeks.
 */

import { isDarkNow } from './dark-mode';

/* -------------------------------------------------------------------------
 * Dark mode by schedule
 * ---------------------------------------------------------------------- */

function applyDarkModeSchedule() {
    const root = document.documentElement;
    const start = root.dataset.darkStart;
    const end = root.dataset.darkEnd;

    if (!start || !end) return;

    root.classList.toggle('dark', isDarkNow(start, end));
}

applyDarkModeSchedule();
setInterval(applyDarkModeSchedule, 60_000);

// The clock and the schedule must not drift while the tab is backgrounded.
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) applyDarkModeSchedule();
});

/* -------------------------------------------------------------------------
 * Rubber-band suppression
 *
 * iOS Safari bounces the whole page on an overscroll even when the body does
 * not scroll, which makes a wall panel look broken. Touches that did not begin
 * inside something genuinely scrollable are cancelled.
 * ---------------------------------------------------------------------- */

document.addEventListener(
    'touchmove',
    (event) => {
        if (!document.body.classList.contains('lock-scroll')) return;
        if (event.touches.length > 1) return;

        const scrollable = event.target.closest('.pane-scroll');

        if (!scrollable) {
            event.preventDefault();
            return;
        }

        // Allow the gesture only where the pane can actually move in that
        // direction; otherwise the scroll chains up to the document.
        const atTop = scrollable.scrollTop <= 0;
        const atBottom = scrollable.scrollTop + scrollable.clientHeight >= scrollable.scrollHeight - 1;

        if (atTop && atBottom) event.preventDefault();
    },
    { passive: false },
);

/* -------------------------------------------------------------------------
 * Service worker (offline shell only)
 * ---------------------------------------------------------------------- */

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // An unregistered worker only costs the offline shell; never block boot.
        });
    });
}
