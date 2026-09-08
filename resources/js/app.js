/**
 * FamilyHub front-end shell.
 *
 * Livewire ships and starts Alpine, so nothing here may import or start Alpine
 * itself. Everything below is plain browser code that has to keep working while
 * the wall display sits untouched for weeks.
 */

import { isDarkNow } from './dark-mode';
import { createDragBoard } from './dragboard';
import { createSilenceWatch, followUntilDone, levelOf } from './listen';
import { startEcho, watchConnection } from './echo';
import { createScreenOff } from './screen-off';
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
 * Kiosk hardening
 *
 * The wall is a Pi running Chromium with no keyboard and no cursor. A long
 * press raising a context menu, or a drag selecting a paragraph of blue text,
 * leaves the screen in a state nobody standing in a kitchen can get out of.
 * ---------------------------------------------------------------------- */

if (document.documentElement.hasAttribute('data-kiosk')) {
    document.addEventListener('contextmenu', (event) => event.preventDefault());

    document.addEventListener('selectstart', (event) => {
        if (!event.target.closest?.('input, textarea, [contenteditable]')) {
            event.preventDefault();
        }
    });

    // Chromium fires this for a two-finger pinch on a touchscreen; the layout
    // is fixed at 1920x1080 and zooming only breaks it.
    document.addEventListener('gesturestart', (event) => event.preventDefault());
}

/* -------------------------------------------------------------------------
 * The wall's microphone
 *
 * Tap, ask, listen. Registered as an Alpine component because the recording
 * has to survive Livewire re-rendering the header underneath it — a question
 * cut short by a tab switch would be a question nobody asks twice.
 *
 * Everything here is glue over MediaRecorder; the deciding lives in listen.js,
 * where it can be tested.
 * ---------------------------------------------------------------------- */

document.addEventListener('alpine:init', () => {
    window.Alpine.data('wallMic', (endpoints = {}) => ({
        // idle | listening | thinking | speaking | failed
        state: 'idle',
        open: false,
        transcript: '',
        answer: '',
        error: '',

        recorder: null,
        stream: null,
        audioContext: null,
        frame: null,
        dismissTimer: null,
        player: null,

        get busy() {
            return this.state === 'listening' || this.state === 'thinking' || this.state === 'speaking';
        },

        press() {
            // The tap that wakes a sleeping wall wakes it and nothing else.
            // The blackout swallows pointerdown at capture, so this is belt and
            // braces — but a microphone that starts recording in a dark kitchen
            // is the one thing here worth being doubly sure about.
            if (window.familyhubScreen?.asleep) return;

            if (this.state === 'listening') return this.finish('tapped');
            if (this.busy) return;

            this.listen();
        },

        async listen() {
            this.reset();
            this.state = 'listening';
            this.open = true;

            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch {
                // On the kiosk this cannot happen — the permission prompt is
                // pre-answered by a Chromium flag. Anywhere else it is a no.
                return this.fail('I need permission to use the microphone.');
            }

            const chunks = [];
            const type = MediaRecorder.isTypeSupported('audio/webm;codecs=opus')
                ? 'audio/webm;codecs=opus'
                : 'audio/webm';

            this.recorder = new MediaRecorder(this.stream, { mimeType: type });
            this.recorder.addEventListener('dataavailable', (e) => e.data.size && chunks.push(e.data));
            this.recorder.addEventListener('stop', () => this.send(new Blob(chunks, { type })));
            this.recorder.start();

            this.watchForSilence();
        },

        /** Stop when the room goes quiet, or at the ceiling. */
        watchForSilence() {
            const watch = createSilenceWatch();

            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();

            const analyser = this.audioContext.createAnalyser();
            analyser.fftSize = 1024;
            this.audioContext.createMediaStreamSource(this.stream).connect(analyser);

            const samples = new Uint8Array(analyser.frequencyBinCount);
            watch.start(Date.now());

            const tick = () => {
                if (this.state !== 'listening') return;

                analyser.getByteTimeDomainData(samples);

                const reason = watch.sample(levelOf(samples), Date.now());

                if (reason) return this.finish(reason);

                this.frame = requestAnimationFrame(tick);
            };

            this.frame = requestAnimationFrame(tick);
        },

        finish(reason) {
            if (this.state !== 'listening') return;

            this.stopListening();

            // Nobody said anything, so there is nothing worth sending.
            if (reason === 'nothing') {
                this.recorder = null;
                this.state = 'failed';
                this.error = "I didn't hear anything.";

                return this.dismissLater();
            }

            this.state = 'thinking';

            if (this.recorder?.state === 'recording') this.recorder.stop();
        },

        async send(blob) {
            if (this.state !== 'thinking') return;

            const body = new FormData();
            body.append('audio', blob, 'question.webm');

            let started;

            try {
                const response = await fetch(endpoints.listen, {
                    method: 'POST',
                    body,
                    // Explicit: the pairing cookie is the only thing that gets
                    // this past the display gate.
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        Accept: 'application/json',
                    },
                });

                started = await response.json();

                if (!response.ok) return this.fail(started.error ?? 'That did not work.');
            } catch {
                return this.fail("I couldn't reach the server.");
            }

            const final = await followUntilDone({
                id: started.id,
                fetchState: async (id) => {
                    const response = await fetch(`${endpoints.listen}/${id}`, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    });

                    return response.json();
                },
                onState: (state) => {
                    if (state.transcript) this.transcript = state.transcript;
                    if (state.answer) this.answer = state.answer;
                },
            });

            if (final.status === 'failed') return this.fail(final.error ?? 'That did not work.');

            this.answer = final.answer ?? '';
            this.say(final);
        },

        say(final) {
            if (!final.speaks) {
                this.state = 'idle';

                return this.dismissLater();
            }

            this.state = 'speaking';
            this.player = new Audio(`${endpoints.speech}/${final.id}`);

            const done = () => {
                this.state = 'idle';
                this.dismissLater();
            };

            this.player.addEventListener('ended', done);
            // A speaker that will not play is not a failed answer: the words
            // are already on the screen.
            this.player.addEventListener('error', done);
            this.player.play().catch(done);
        },

        fail(message) {
            this.state = 'failed';
            this.error = message;
            this.stopListening();
            this.dismissLater();
        },

        /** Let go of the microphone the moment we stop needing it. */
        stopListening() {
            if (this.frame) cancelAnimationFrame(this.frame);
            this.frame = null;

            this.stream?.getTracks().forEach((track) => track.stop());
            this.stream = null;

            this.audioContext?.close();
            this.audioContext = null;
        },

        /** The wall clears itself: nobody walks back to dismiss a dialog. */
        dismissLater(ms = 30_000) {
            clearTimeout(this.dismissTimer);
            this.dismissTimer = setTimeout(() => this.dismiss(), ms);
        },

        /**
         * A tap on the dialog.
         *
         * While it is listening this ends the question, which is the only way
         * a second tap can reach anything: the dialog covers the whole screen,
         * mic button included, the moment it opens.
         */
        tapped() {
            if (this.state === 'listening') return this.finish('tapped');

            this.dismiss();
        },

        dismiss() {
            // Not mid-question: a stray tap must not throw away an answer
            // that is on its way.
            if (this.state === 'listening' || this.state === 'thinking') return;

            this.player?.pause();
            this.player = null;
            this.open = false;
            this.state = 'idle';
            this.reset();
        },

        reset() {
            clearTimeout(this.dismissTimer);
            this.transcript = '';
            this.answer = '';
            this.error = '';
        },

        destroy() {
            this.stopListening();
            clearTimeout(this.dismissTimer);
        },
    }));
});

/* -------------------------------------------------------------------------
 * Overnight screen off
 *
 * The kiosk monitor cannot be power-cycled remotely, so "off" is a full-black
 * layer over the page, woken by a touch and settling back down on its own.
 *
 * Plain and framework-free on purpose: this has to keep working when the
 * network is down and Livewire never booted. A wall that stays lit all night
 * because a websocket failed is a wall somebody unplugs.
 * ---------------------------------------------------------------------- */

const kioskRoot = document.documentElement;

if (kioskRoot.dataset.screenOffStart && kioskRoot.dataset.screenOffEnd) {
    const blackout = document.createElement('div');
    blackout.className = 'screen-off';
    blackout.setAttribute('aria-hidden', 'true');
    blackout.hidden = true;

    const screen = createScreenOff({
        start: kioskRoot.dataset.screenOffStart,
        end: kioskRoot.dataset.screenOffEnd,
        timeZone: kioskRoot.dataset.timezone || undefined,
        onChange: (asleep) => {
            if (asleep) {
                blackout.hidden = false;
                // Painted before fading in, or the first frame is a flash.
                requestAnimationFrame(() => (blackout.style.opacity = '1'));
            } else {
                blackout.style.opacity = '0';
                setTimeout(() => (blackout.hidden = true), 400);
            }
        },
    });

    document.addEventListener('DOMContentLoaded', () => document.body.append(blackout));

    // Capture, so the tap that wakes the wall is swallowed rather than also
    // ticking off whatever chore happened to be underneath it.
    for (const type of ['pointerdown', 'touchstart', 'keydown']) {
        window.addEventListener(
            type,
            (event) => {
                if (screen.touch()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            },
            { capture: true, passive: false },
        );
    }

    screen.evaluate();
    setInterval(() => screen.evaluate(), 15000);

    // Coming back from a backgrounded tab, or a clock that jumped.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) screen.evaluate();
    });

    window.familyhubScreen = screen;
}

/* -------------------------------------------------------------------------
 * Realtime, if it is switched on
 *
 * Home Assistant state changes arrive as a nudge over Reverb; Livewire
 * components listen for it and re-read their own local cache. With no Reverb
 * configured none of this runs and the wall polls as it always did.
 * ---------------------------------------------------------------------- */

const echoMeta = document.querySelector('meta[name="reverb-key"]');

if (echoMeta?.content) {
    startEcho({
        key: echoMeta.content,
        host: echoMeta.dataset.host,
        port: Number(echoMeta.dataset.port) || 443,
        scheme: echoMeta.dataset.scheme || 'https',
        path: echoMeta.dataset.path || '',
    })
        .then((echo) => {
            if (!echo) return;

            window.Echo = echo;

            // Published on the document so any component can slow its own poll
            // down while the socket is doing the work.
            watchConnection(echo, (connected) => {
                document.documentElement.dataset.realtime = connected ? 'on' : 'off';
                window.dispatchEvent(new CustomEvent('realtime-changed', { detail: { connected } }));
            });
        })
        .catch(() => {
            // A wall that cannot reach Reverb still polls. Nothing to say.
            document.documentElement.dataset.realtime = 'off';
        });
}

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
