/**
 * FamilyHub front-end shell.
 *
 * Livewire ships and starts Alpine, so nothing here may import or start Alpine
 * itself. Everything below is plain browser code that has to keep working while
 * the wall display sits untouched for weeks.
 */

import { isDarkNow } from './dark-mode';
import { createDragBoard } from './dragboard';
import { createUpdater } from './updater';

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
 * Self-updating display
 *
 * The wall iPad is never reloaded by hand, so it polls for the deployed build
 * id and reloads itself when that changes — but only once nobody has touched
 * the screen for a while, so it never reloads under someone's finger.
 * ---------------------------------------------------------------------- */

const versionMeta = document.querySelector('meta[name="build-version"]');

if (versionMeta) {
    const updater = createUpdater({
        version: versionMeta.content,
        endpoint: versionMeta.dataset.endpoint || '/version',
        pollMs: Number(versionMeta.dataset.pollMs) || undefined,
        idleMs: Number(versionMeta.dataset.idleMs) || undefined,
        dailyReloadAt: versionMeta.dataset.dailyAt || undefined,
    });

    for (const event of ['pointerdown', 'keydown', 'touchstart', 'wheel']) {
        window.addEventListener(event, () => updater.touched(), { passive: true });
    }

    setInterval(() => updater.tick(), updater.config.pollMs);

    // Coming back from background is the cheapest chance to catch up, and the
    // moment a stale page is most likely to be noticed.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) updater.tick();
    });

    // A worker that has taken over is, by definition, a new build.
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('controllerchange', () => updater.reloadWhenIdle());
    }

    window.familyhubUpdater = updater;
}

/* -------------------------------------------------------------------------
 * Meal plan drag-and-drop
 *
 * Registered as an Alpine component rather than wired up per element: the meal
 * grid appears on the wall and on phones, and both need identical behaviour.
 * Livewire owns Alpine, so this waits for it rather than starting it.
 * ---------------------------------------------------------------------- */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('mealBoard', () => ({
        board: null,
        drag: { dragging: null, x: 0, y: 0, over: null },

        init() {
            this.board = createDragBoard({
                onDrop: (payload, target) => {
                    const [on, slot] = target.split('|');

                    // Two kinds of thing get dragged onto a day: a meal
                    // already on the grid, which moves, and an idea off the
                    // shelf, which is placed.
                    const idea = String(payload.id).match(/^recipe:(\d+)$/);

                    idea
                        ? this.$wire.place(Number(idea[1]), on, slot)
                        : this.$wire.move(payload.id, on, slot);
                },
                onChange: (state) => {
                    this.drag = state;
                    // Lifting a meal must not also scroll the grid under it.
                    document.body.classList.toggle('dragging-meal', state.dragging !== null);
                },
            });
        },

        lift(event, id, from, title) {
            // Mouse right-clicks and multi-touch are not drags.
            if (event.button > 0 || !event.isPrimary) return;

            this.board.down(event, { id, from, title });
        },

        track(event) {
            if (this.board.move(event)) event.preventDefault();
        },

        release(event) {
            // Swallow the click that follows a real drag, or the cell editor
            // opens on top of the move that just happened.
            if (this.board.up(event)) event.preventDefault();
        },
    }));
});

/* -------------------------------------------------------------------------
 * Service worker (offline shell only)
 * ---------------------------------------------------------------------- */

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker
            .register('/sw.js')
            .then((registration) => {
                // Pick up a new worker promptly rather than on the next cold start.
                registration.update();
                setInterval(() => registration.update(), 60 * 60 * 1000);
            })
            .catch(() => {
                // An unregistered worker only costs the offline shell; never block boot.
            });
    });
}
