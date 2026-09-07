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
 * Visual viewport tracking
 *
 * iOS shrinks the visual viewport when the on-screen keyboard appears, without
 * moving the layout viewport that `position: fixed` is measured against. A
 * dialog centred the ordinary way therefore ends up behind the keyboard. These
 * custom properties let `.modal-viewport` follow the part of the screen the
 * user can actually see, and reserve the tab bar's height so a dialog never
 * covers it.
 * ---------------------------------------------------------------------- */

function syncVisualViewport() {
    const root = document.documentElement;
    const tabBar = document.querySelector('[data-tab-bar]');

    root.style.setProperty('--tab-bar-height', `${tabBar ? tabBar.offsetHeight : 0}px`);

    const viewport = window.visualViewport;

    if (!viewport) {
        root.style.setProperty('--vv-top', '0px');
        root.style.setProperty('--vv-height', `${window.innerHeight}px`);
        return;
    }

    root.style.setProperty('--vv-top', `${viewport.offsetTop}px`);
    root.style.setProperty('--vv-height', `${viewport.height}px`);

    // A large shortfall against the layout viewport means a keyboard, not a
    // scrolled-away browser bar.
    root.classList.toggle('keyboard-open', window.innerHeight - viewport.height > 120);
}

syncVisualViewport();

window.addEventListener('resize', syncVisualViewport);
window.addEventListener('orientationchange', syncVisualViewport);

if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', syncVisualViewport);
    window.visualViewport.addEventListener('scroll', syncVisualViewport);
}

// Livewire swaps DOM around; the tab bar may not have existed on first run.
document.addEventListener('livewire:navigated', syncVisualViewport);
document.addEventListener('DOMContentLoaded', syncVisualViewport);

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
