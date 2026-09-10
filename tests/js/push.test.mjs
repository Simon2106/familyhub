import { strict as assert } from 'node:assert';
import test from 'node:test';

import {
    describePermission,
    isStandalone,
    looksLikeIos,
    unavailableReason,
} from '../../resources/js/push.js';

/* ------------------------------ permission ----------------------------- */

test('a permission nobody has been asked for says so', () => {
    const described = describePermission('default', { ios: false });

    assert.equal(described.label, 'Not asked yet');
    assert.equal(described.detail, null);
});

test('granted needs no further instruction', () => {
    assert.deepEqual(describePermission('granted', { ios: true }), {
        state: 'granted',
        label: 'Allowed',
        detail: null,
    });
});

/**
 * The important one. Denied cannot be re-asked from script, and on iOS it is
 * not undone in Safari's settings either — it is under the installed app's own
 * entry. Somebody not told that taps a button that will never work again.
 */
test('blocked on iOS points at iOS Settings, not the browser', () => {
    const described = describePermission('denied', { ios: true });

    assert.equal(described.label, 'Blocked in iOS Settings');
    assert.match(described.detail, /Settings → Notifications → FamilyHub/);
});

test('blocked elsewhere points at the browser', () => {
    const described = describePermission('denied', { ios: false });

    assert.equal(described.label, 'Blocked in this browser');
    assert.match(described.detail, /browser’s settings/);
});

test('an iPhone in a Safari tab is told to install first', () => {
    const described = describePermission('default', { ios: true, standalone: false });

    assert.match(described.detail, /Add to Home Screen/);
});

test('an installed iPhone app is not', () => {
    assert.equal(describePermission('default', { ios: true, standalone: true }).detail, null);
});

/* ----------------------------- availability ---------------------------- */

const browser = (over = {}) => ({
    navigator: { serviceWorker: {}, platform: 'MacIntel', maxTouchPoints: 0, ...over.navigator },
    PushManager: function PushManager() {},
    Notification: function Notification() {},
    matchMedia: () => ({ matches: false }),
    ...over,
});

test('no key on the server is the servers problem, and is said first', () => {
    assert.match(unavailableReason(browser(), ''), /No push key/);
});

test('a capable installed browser has no reason at all', () => {
    assert.equal(unavailableReason(browser(), 'key', { ios: false, standalone: true }), null);
});

test('iOS outside an installed app cannot do push, whatever else is true', () => {
    const reason = unavailableReason(browser(), 'key', { ios: true, standalone: false });

    assert.match(reason, /Home Screen/);
});

test('a browser missing the APIs says so', () => {
    const win = browser();
    delete win.PushManager;

    assert.match(unavailableReason(win, 'key', { ios: false, standalone: true }), /cannot receive/);
});

/* ------------------------------ sniffing ------------------------------- */

test('an iPad reports itself as a Mac and is told apart by the touchscreen', () => {
    assert.equal(looksLikeIos({ navigator: { platform: 'MacIntel', maxTouchPoints: 5 } }), true);
    assert.equal(looksLikeIos({ navigator: { platform: 'MacIntel', maxTouchPoints: 0 } }), false);
});

test('an iPhone is an iPhone', () => {
    assert.equal(looksLikeIos({ navigator: { platform: 'iPhone' } }), true);
});

test('standalone is either flag', () => {
    assert.equal(isStandalone({ navigator: { standalone: true }, matchMedia: () => ({ matches: false }) }), true);
    assert.equal(isStandalone({ navigator: {}, matchMedia: () => ({ matches: true }) }), true);
    assert.equal(isStandalone({ navigator: {}, matchMedia: () => ({ matches: false }) }), false);
});

/* ------------------------- the iOS gesture rule ------------------------ */

/**
 * The bug this file exists because of, guarded the only way it can be.
 *
 * WebKit spends the user activation on the first await, after which
 * requestPermission() resolves to "default" without ever showing a prompt —
 * silently, with nothing to catch. Chromium is more forgiving, so no browser
 * available here reproduces it and no functional test can guard it.
 *
 * What can be guarded is the shape of the code: the ask has to be reached
 * synchronously from the tap.
 */
test('toggle() reaches requestPermission with no await in front of it', async () => {
    const { readFile } = await import('node:fs/promises');
    const source = await readFile(new URL('../../resources/js/app.js', import.meta.url), 'utf8');

    const start = source.indexOf('        toggle() {');

    assert.notEqual(start, -1, 'toggle() must exist and must not be declared async');
    assert.equal(
        source.includes('async toggle()'),
        false,
        'toggle() must not be async — an async handler is one await away from losing the gesture',
    );

    // The body, up to the closing brace at its own indentation.
    const body = source.slice(start, source.indexOf('\n        },', start));
    const ask = body.indexOf('Notification.requestPermission');

    assert.notEqual(ask, -1, 'toggle() must be the thing that asks for permission');

    const before = body.slice(0, ask);

    assert.equal(
        /\bawait\b/.test(before),
        false,
        'nothing may be awaited before requestPermission() — WebKit spends the user activation on it',
    );
});
