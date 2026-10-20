import assert from 'node:assert/strict';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { loadFontCoverages } from '../lib/fonts.mjs';
import { headlineTokens, sanitizeText, sublineParts, textLength, withoutAccentMarks } from '../lib/text.mjs';
import { BAIT_SCRIPTS } from '../testkit/helpers.mjs';

const fontsDir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'template', 'fonts');
const isCovered = loadFontCoverages(fontsDir)['noto-serif'];

test('text sanitizer: control characters, emoji and unsupported scripts are removed', () => {
    assert.equal(sanitizeText('Hi\u0000 \u0007there\u{200b}', isCovered), 'Hi there');
    assert.equal(sanitizeText('Sale 🔥🔥 now 👍🏽 ❤️', isCovered), 'Sale now');
    assert.equal(sanitizeText('  a \t\n  b\u{00a0}\u{2028}c  ', isCovered), 'a b c');
    assert.equal(sanitizeText('日本語 text', isCovered), 'text');
    assert.equal(sanitizeText('مرحبا', isCovered), '');
});

test('text sanitizer: copyright, registered and trademark signs are kept, real emoji are not', () => {
    assert.equal(sanitizeText('Maisto\u{00ae} GT3', isCovered), 'Maisto\u{00ae} GT3');
    assert.equal(sanitizeText('PlayCraft\u{2122} Sale', isCovered), 'PlayCraft\u{2122} Sale');
    assert.equal(sanitizeText('\u{00a9} 2026', isCovered), '\u{00a9} 2026');
    assert.equal(sanitizeText('Sale 🔥 \u{2122}\u{fe0f}', isCovered), 'Sale \u{2122}');
});

test('text sanitizer: Latin, Greek and Cyrillic bait texts survive unchanged (font coverage)', () => {
    for (const bait of BAIT_SCRIPTS) {
        assert.equal(sanitizeText(bait, isCovered), bait);
    }
});

test('text sanitizer: markup characters stay as plain text characters', () => {
    const bait = '</script><img src=x onerror=alert(1)>';
    assert.equal(sanitizeText(bait, isCovered), bait);
});

test('text length counts characters, not UTF-16 units', () => {
    assert.equal(textLength('ÄÖÜß'), 4);
    assert.equal(textLength('a𝒳b'), 3);
    assert.equal(textLength(withoutAccentMarks('*Peppa*!')), 6);
});

test('accent word: exactly one word is marked, punctuation after the closing mark belongs to it', () => {
    assert.deepEqual(headlineTokens('Camping time with *Peppa*!', isCovered), [
        { t: 'Camping', a: false },
        { t: 'time', a: false },
        { t: 'with', a: false },
        { t: 'Peppa!', a: true },
    ]);
    assert.deepEqual(headlineTokens('*Sale*?!', isCovered), [{ t: 'Sale?!', a: true }]);
});

test('accent word: with two marked words only the first one is accented, all marks are removed', () => {
    assert.deepEqual(headlineTokens('*One* and *Two*', isCovered), [
        { t: 'One', a: true },
        { t: 'and', a: false },
        { t: 'Two', a: false },
    ]);
});

test('accent word: an unpaired mark accents nothing and loses its star', () => {
    assert.deepEqual(headlineTokens('*half and half*', isCovered), [
        { t: 'half', a: false },
        { t: 'and', a: false },
        { t: 'half', a: false },
    ]);
    assert.deepEqual(headlineTokens('open *star', isCovered), [{ t: 'open', a: false }, { t: 'star', a: false }]);
});

test('accent word: empty marks (**, ***) accent nothing and leave no empty word', () => {
    assert.deepEqual(headlineTokens('a ** b', isCovered), [{ t: 'a', a: false }, { t: 'b', a: false }]);
    assert.deepEqual(headlineTokens('a *** b *real*', isCovered), [{ t: 'a', a: false }, { t: 'b', a: false }, { t: 'real', a: true }]);
});

test('accent word: Greek and Cyrillic words carry the accent too', () => {
    assert.deepEqual(headlineTokens('Ώρα για *Peppa* ПРОБУЖДАЕТСЯ', isCovered).map((word) => word.a), [false, false, true, false]);
});

test('accent word: a very long unterminated run of stars stays fast', () => {
    const started = Date.now();
    headlineTokens(`*${'*a'.repeat(40)}`, isCovered);
    assert.ok(Date.now() - started < 200);
});

test('subline: parts are split around the dot separator and empty parts disappear', () => {
    assert.deepEqual(sublineParts('Now in store · Paphos', isCovered), ['Now in store', 'Paphos']);
    assert.deepEqual(sublineParts('Only text', isCovered), ['Only text']);
    assert.deepEqual(sublineParts('  ·  ', isCovered), []);
    assert.deepEqual(sublineParts('🔥', isCovered), []);
});

test('text sanitizer: the emoji filter works on its own, even for a font that claims to cover every code point', () => {
    const coversEverything = () => true;

    assert.equal(sanitizeText('Sale \u{1F525}\u{1F525} now \u{1F44D}\u{1F3FD} \u{2764}\u{FE0F}', coversEverything), 'Sale now');
    assert.equal(sanitizeText('flag \u{1F1E8}\u{1F1FE} here', coversEverything), 'flag here');
    assert.equal(sanitizeText('Maisto\u{00ae} \u{2122}', coversEverything), 'Maisto\u{00ae} \u{2122}');
});
