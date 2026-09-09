/**
 * An on-screen keyboard for the wall.
 *
 * The kiosk is a Raspberry Pi driving a touchscreen through Chromium, and
 * Chromium on a desktop Linux has no soft keyboard of its own. Without this,
 * every text field on the wall — a meal, a to-do, a search — is a field nobody
 * standing at the wall can fill in.
 *
 * The deciding is kept free of the DOM so it can be tested; what is left is
 * glue over an element and a focused field.
 */

/** Where the letters are. Rows are drawn in order. */
export const LETTERS = [
    ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
    ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'],
    ['shift', 'z', 'x', 'c', 'v', 'b', 'n', 'm', 'backspace'],
];

export const SYMBOLS = [
    ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
    ['-', '/', ':', ';', '(', ')', '£', '&', '@'],
    ['symbols2', '.', ',', '?', '!', "'", '"', '+', 'backspace'],
];

export const SYMBOLS_MORE = [
    ['[', ']', '{', '}', '#', '%', '^', '*', '+', '='],
    ['_', '\\', '|', '~', '<', '>', '$', '€', '•'],
    ['symbols1', '.', ',', '?', '!', "'", '"', '-', 'backspace'],
];

/** The rows to draw for a layer. */
export function rowsFor(layer) {
    return { letters: LETTERS, symbols: SYMBOLS, more: SYMBOLS_MORE }[layer] ?? LETTERS;
}

/** What a key shows, given the shift state. */
export function labelFor(key, shifted) {
    if (key === 'shift') return '⇧';
    if (key === 'backspace') return '⌫';
    if (key === 'symbols2') return '#+=';
    if (key === 'symbols1') return '123';

    return shifted ? key.toUpperCase() : key;
}

/**
 * Apply one key press to a field's value.
 *
 * Pure, and given the selection rather than the element, so every rule — what
 * backspace does to a selection, where the caret lands after a space — can be
 * tested without a browser.
 */
export function applyKey({ value, start, end, key, shifted = false }) {
    const before = value.slice(0, start);
    const after = value.slice(end);

    if (key === 'backspace') {
        // A selection is deleted whole; otherwise one character goes.
        return start === end
            ? { value: value.slice(0, Math.max(0, start - 1)) + after, caret: Math.max(0, start - 1) }
            : { value: before + after, caret: start };
    }

    const text = key === 'space' ? ' ' : key === 'enter' ? '\n' : shifted ? key.toUpperCase() : key;

    return { value: before + text + after, caret: start + text.length };
}

/**
 * Whether shift should still be on after typing something.
 *
 * One letter and it releases, the way every phone keyboard behaves — a shift
 * that stayed on would have somebody typing THE REST OF THE MEAL IN CAPITALS.
 * Locked shift (a double tap) stays until it is tapped off.
 */
export function shiftAfter(key, { shifted, locked }) {
    if (key === 'shift') {
        // off -> on -> locked -> off
        return shifted ? (locked ? { shifted: false, locked: false } : { shifted: true, locked: true })
            : { shifted: true, locked: false };
    }

    if (key === 'backspace') return { shifted, locked };

    return locked ? { shifted, locked } : { shifted: false, locked: false };
}

/** The fields worth putting a keyboard up for. */
export function wantsKeyboard(element) {
    if (!element) return false;

    if (element.tagName === 'TEXTAREA') return true;
    if (element.tagName !== 'INPUT') return false;

    return ['text', 'search', 'url', 'email', 'tel', 'number', 'password', ''].includes(
        (element.getAttribute('type') || 'text').toLowerCase(),
    );
}

/* -------------------------------------------------------------------------
 * The keyboard itself
 * ---------------------------------------------------------------------- */

/**
 * Build the keyboard and wire it to whatever has focus.
 *
 * Two things it has to get right:
 *
 *   Never take focus. Every key cancels its own pointerdown, because a field
 *   that blurs on the way to a key is a field the next keystroke misses.
 *
 *   Move the page up. There is no real keyboard here, so nothing shrinks the
 *   visual viewport on its own — this sets the same --vv-top/--vv-height the
 *   modal component already measures against, which is what makes every
 *   dialog in the app rise above it without knowing this exists.
 */
export function createKeyboard({
    root = document.documentElement,
    body = document.body,
    onShow = () => {},
    onHide = () => {},
} = {}) {
    let layer = 'letters';
    let shift = { shifted: false, locked: false };
    let field = null;
    let element = null;

    function render() {
        if (!element) return;

        element.innerHTML = '';

        for (const row of rowsFor(layer)) {
            const line = document.createElement('div');
            line.className = 'kb-row';

            for (const key of row) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'kb-key';
                button.dataset.key = key;
                button.textContent = labelFor(key, shift.shifted);

                if (key === 'shift') {
                    button.classList.add('kb-wide');
                    button.classList.toggle('kb-on', shift.shifted);
                    button.classList.toggle('kb-locked', shift.locked);
                }
                if (key === 'backspace' || key === 'symbols1' || key === 'symbols2') {
                    button.classList.add('kb-wide');
                }

                line.append(button);
            }

            element.append(line);
        }

        // The bottom row is fixed: layer, space, done.
        const bottom = document.createElement('div');
        bottom.className = 'kb-row';

        for (const [key, label, className] of [
            [layer === 'letters' ? 'symbols' : 'letters', layer === 'letters' ? '123' : 'ABC', 'kb-wide'],
            ['space', 'space', 'kb-space'],
            [field?.tagName === 'TEXTAREA' ? 'enter' : 'done', field?.tagName === 'TEXTAREA' ? '⏎' : 'Done', 'kb-wide kb-done'],
        ]) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `kb-key ${className}`;
            button.dataset.key = key;
            button.textContent = label;
            bottom.append(button);
        }

        element.append(bottom);
    }

    function press(key) {
        if (!field) return;

        if (key === 'letters' || key === 'symbols' || key === 'more') {
            layer = key;

            return render();
        }

        if (key === 'symbols2') {
            layer = 'more';

            return render();
        }

        if (key === 'symbols1') {
            layer = 'symbols';

            return render();
        }

        if (key === 'done') return hide({ blur: true });

        if (key === 'shift') {
            shift = shiftAfter(key, shift);

            return render();
        }

        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? start;
        const { value, caret } = applyKey({ value: field.value, start, end, key, shifted: shift.shifted });

        field.value = value;

        // Livewire and Alpine both listen for this; without it the model keeps
        // whatever it had before anybody typed.
        field.dispatchEvent(new Event('input', { bubbles: true }));

        try {
            field.setSelectionRange(caret, caret);
        } catch {
            // Number inputs refuse a selection range, and do not need one.
        }

        const next = shiftAfter(key, shift);

        if (next.shifted !== shift.shifted || next.locked !== shift.locked) {
            shift = next;
            render();
        }
    }

    function show(target) {
        field = target;
        layer = 'letters';
        shift = { shifted: false, locked: false };

        element.hidden = false;
        render();

        // The same variables the modal measures itself against, so a dialog
        // rises above the keyboard without being told about it.
        const height = element.offsetHeight;
        root.style.setProperty('--vv-height', `${window.innerHeight - height}px`);
        root.classList.add('keyboard-open', 'kb-up');

        // And the field itself, which may be nowhere near a dialog.
        requestAnimationFrame(() => field?.scrollIntoView({ block: 'center', behavior: 'smooth' }));

        onShow(height);
    }

    function hide({ blur = false } = {}) {
        if (element.hidden) return;

        if (blur && field) field.blur();

        element.hidden = true;
        field = null;

        root.style.removeProperty('--vv-height');
        root.classList.remove('keyboard-open', 'kb-up');

        onHide();
    }

    element = document.createElement('div');
    element.className = 'kb';
    element.hidden = true;
    element.setAttribute('role', 'group');
    element.setAttribute('aria-label', 'On-screen keyboard');

    // Cancelled before it starts: a key that took focus would blur the field
    // it is meant to be typing into.
    element.addEventListener('pointerdown', (event) => event.preventDefault());
    element.addEventListener('click', (event) => {
        const key = event.target.closest('[data-key]');

        if (key) press(key.dataset.key);
    });

    body.append(element);

    return {
        get element() {
            return element;
        },
        get field() {
            return field;
        },
        get visible() {
            return !element.hidden;
        },
        show,
        hide,
        press,
    };
}
