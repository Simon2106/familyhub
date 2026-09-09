import { strict as assert } from 'node:assert';
import test from 'node:test';

import {
    LETTERS,
    applyKey,
    labelFor,
    rowsFor,
    shiftAfter,
    wantsKeyboard,
} from '../../resources/js/keyboard.js';

/* ------------------------------- typing -------------------------------- */

test('a letter lands where the caret is', () => {
    assert.deepEqual(applyKey({ value: 'fjitas', start: 1, end: 1, key: 'a' }), {
        value: 'fajitas',
        caret: 2,
    });
});

test('shift types a capital', () => {
    assert.deepEqual(applyKey({ value: '', start: 0, end: 0, key: 'f', shifted: true }), {
        value: 'F',
        caret: 1,
    });
});

test('space and enter are characters like any other', () => {
    assert.equal(applyKey({ value: 'fish', start: 4, end: 4, key: 'space' }).value, 'fish ');
    assert.equal(applyKey({ value: 'one', start: 3, end: 3, key: 'enter' }).value, 'one\n');
});

test('backspace takes one character back', () => {
    assert.deepEqual(applyKey({ value: 'fish', start: 4, end: 4, key: 'backspace' }), {
        value: 'fis',
        caret: 3,
    });
});

test('backspace at the start of an empty field does nothing bad', () => {
    assert.deepEqual(applyKey({ value: '', start: 0, end: 0, key: 'backspace' }), {
        value: '',
        caret: 0,
    });
});

test('backspace deletes a selection whole', () => {
    assert.deepEqual(applyKey({ value: 'fish pie', start: 0, end: 5, key: 'backspace' }), {
        value: 'pie',
        caret: 0,
    });
});

test('typing over a selection replaces it', () => {
    assert.deepEqual(applyKey({ value: 'fish pie', start: 0, end: 4, key: 'c' }), {
        value: 'c pie',
        caret: 1,
    });
});

/* -------------------------------- shift -------------------------------- */

test('shift releases after one letter', () => {
    // Otherwise the rest of the meal is typed IN CAPITALS.
    const shifted = shiftAfter('shift', { shifted: false, locked: false });

    assert.deepEqual(shifted, { shifted: true, locked: false });
    assert.deepEqual(shiftAfter('f', shifted), { shifted: false, locked: false });
});

test('a second tap locks it, and a third lets it go', () => {
    const on = shiftAfter('shift', { shifted: false, locked: false });
    const locked = shiftAfter('shift', on);

    assert.deepEqual(locked, { shifted: true, locked: true });
    assert.deepEqual(shiftAfter('f', locked), { shifted: true, locked: true }, 'Locked stays.');
    assert.deepEqual(shiftAfter('shift', locked), { shifted: false, locked: false });
});

test('backspace does not release shift', () => {
    const on = { shifted: true, locked: false };

    assert.deepEqual(shiftAfter('backspace', on), on);
});

/* ------------------------------- layout -------------------------------- */

test('the letters are a qwerty keyboard', () => {
    assert.equal(LETTERS[0].join(''), 'qwertyuiop');
    assert.equal(rowsFor('letters'), LETTERS);
    assert.equal(rowsFor('nonsense'), LETTERS, 'An unknown layer falls back rather than blanks.');
});

test('the special keys read as symbols, not as words', () => {
    assert.equal(labelFor('shift', false), '⇧');
    assert.equal(labelFor('backspace', false), '⌫');
    assert.equal(labelFor('a', true), 'A');
    assert.equal(labelFor('a', false), 'a');
});

/* ---------------------------- which fields ----------------------------- */

test('it comes up for the fields somebody types into', () => {
    const field = (tag, type) => ({
        tagName: tag,
        getAttribute: () => type,
    });

    assert.equal(wantsKeyboard(field('TEXTAREA')), true);
    assert.equal(wantsKeyboard(field('INPUT', 'text')), true);
    assert.equal(wantsKeyboard(field('INPUT', 'search')), true);
    assert.equal(wantsKeyboard(field('INPUT', 'url')), true);
    assert.equal(wantsKeyboard(field('INPUT', null)), true, 'A type-less input is a text input.');
});

test('and not for the ones a keyboard cannot help with', () => {
    const field = (tag, type) => ({ tagName: tag, getAttribute: () => type });

    assert.equal(wantsKeyboard(field('INPUT', 'checkbox')), false);
    assert.equal(wantsKeyboard(field('INPUT', 'file')), false);
    assert.equal(wantsKeyboard(field('INPUT', 'date')), false);
    assert.equal(wantsKeyboard(field('BUTTON')), false);
    assert.equal(wantsKeyboard(null), false);
});
