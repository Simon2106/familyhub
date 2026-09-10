/**
 * Web Push, and what iOS makes of it.
 *
 * Split out from app.js because the interesting parts are decisions rather
 * than DOM: what a permission state should be *called* in a sentence somebody
 * can act on, and whether this browser can do push at all. Both are worth
 * testing, and neither needs a browser to test.
 *
 * The rule that governs the whole thing, and that this file exists to keep
 * anybody from breaking again:
 *
 *   Notification.requestPermission() must be reached synchronously from the
 *   tap. WebKit spends the user activation on the first await, and afterwards
 *   the call resolves to "default" without ever showing a prompt — silently,
 *   with no error to catch. So permission is asked for first, before the
 *   service worker is looked up, before anything is fetched.
 */

/** What the browser says, and what it means to somebody standing there. */
export const PERMISSION_WORDS = {
    granted: 'Allowed',
    denied: 'Blocked',
    default: 'Not asked yet',
};

/**
 * The state, in words, with what to do about it.
 *
 * "Blocked" is the one that matters: it cannot be undone from script, and on
 * iOS it is not undone in Safari's settings either — it is per-app, under the
 * installed web app's own entry in Settings. Somebody who is not told that
 * taps a button that will never work again.
 *
 * @returns {{state: string, label: string, detail: string|null}}
 */
export function describePermission(permission, { ios = false, standalone = true } = {}) {
    const label = PERMISSION_WORDS[permission] ?? 'Unknown';

    if (permission === 'denied') {
        return {
            state: permission,
            label: ios ? 'Blocked in iOS Settings' : 'Blocked in this browser',
            detail: ios
                ? 'Settings → Notifications → FamilyHub, and turn Allow Notifications on. It cannot be re-asked from here.'
                : 'Allow notifications for this site in the browser’s settings. It cannot be re-asked from here.',
        };
    }

    if (permission === 'granted') {
        return { state: permission, label, detail: null };
    }

    // Not asked yet. On iOS that is only answerable from an installed app.
    return {
        state: permission,
        label,
        detail: ios && !standalone
            ? 'On iPhone, notifications only work once this is added to the Home Screen. Share → Add to Home Screen, then open it from there.'
            : null,
    };
}

/** Running as an installed app rather than a browser tab. */
export function isStandalone(win = window) {
    return Boolean(
        win.navigator?.standalone === true ||
            win.matchMedia?.('(display-mode: standalone)')?.matches,
    );
}

/**
 * iPhone or iPad, without asking the user agent what it is called.
 *
 * The only thing this changes is the wording of an instruction — "iOS
 * Settings" rather than "this browser's settings" — so a wrong guess costs a
 * confusing sentence rather than broken behaviour.
 */
export function looksLikeIos(win = window) {
    const nav = win.navigator ?? {};

    // iPadOS reports itself as a Mac, and is told apart by having a
    // touchscreen, which no actual Mac has.
    return (
        /iPad|iPhone|iPod/.test(nav.platform ?? '') ||
        (nav.platform === 'MacIntel' && (nav.maxTouchPoints ?? 0) > 1)
    );
}

/**
 * Why push is unavailable, or null when it is available.
 *
 * Ordered by what somebody can do about it: a missing key is the server's
 * problem, a browser tab on iOS is theirs, and an old browser is nobody's.
 */
export function unavailableReason(win = window, publicKey = '', options = {}) {
    const ios = options.ios ?? looksLikeIos(win);
    const standalone = options.standalone ?? isStandalone(win);

    if (!publicKey) {
        return 'No push key is set on the server yet.';
    }

    if (ios && !standalone) {
        return 'On iPhone and iPad, notifications only work from an app added to the Home Screen. Share → Add to Home Screen, then open it from there.';
    }

    if (!('serviceWorker' in win.navigator) || !('PushManager' in win) || !('Notification' in win)) {
        return 'This browser cannot receive notifications.';
    }

    return null;
}

/** The VAPID key, as the subscribe call wants it. */
export function keyToBytes(base64) {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(padded);
    const bytes = new Uint8Array(raw.length);

    for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);

    return bytes;
}
