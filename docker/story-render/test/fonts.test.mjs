import assert from 'node:assert/strict';
import { copyFileSync, mkdtempSync, readFileSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { FONT_KEYS, FONTS, fontFiles, loadFontCoverages, SUBSETS } from '../lib/fonts.mjs';
import { sanitizeText } from '../lib/text.mjs';

const templateDir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'template');
const fontsDir = path.join(templateDir, 'fonts');
const coverages = loadFontCoverages(fontsDir);

const codePoints = (text) => [...text].map((char) => char.codePointAt(0));

// Every family must draw Latin with umlauts, Greek and Cyrillic from its own files, and must not claim CJK or Arabic.
for (const key of FONT_KEYS) {
    test(`font coverage: ${key}`, () => {
        const covered = coverages[key];
        for (const sample of ['Camping time with Peppa', 'ÄÖÜäöüß', 'ÀÉÎÕÇ ñ', 'Ελληνικά Παιχνίδια ΏΡΑ', 'ПРОБУЖДАЕТСЯ пробуждается', '€ © ® ™ · ! ? . ,', '0123456789']) {
            for (const codePoint of codePoints(sample)) {
                assert.ok(covered(codePoint), `${key} lacks U+${codePoint.toString(16)} of "${sample}"`);
            }
        }
        for (const missing of ['日本語', 'مرحبا', '한국어', '🔥']) {
            assert.equal(covered(missing.codePointAt(0)), false, `${key} must not cover ${missing}`);
        }
        assert.equal(sanitizeText('Ελληνικά ПРОБУЖДАЕТСЯ Größe ÄÖÜß 日本', covered), 'Ελληνικά ПРОБУЖДАЕТСЯ Größe ÄÖÜß');
    });
}

test('fonts: every family ships its four subsets and an OFL text with a unique name', () => {
    const present = new Set(readdirSync(fontsDir));
    for (const key of FONT_KEYS) {
        assert.equal(fontFiles(key).length, SUBSETS.length);
        for (const file of fontFiles(key)) {
            assert.ok(present.has(file), `${file} missing`);
        }
    }
    const licences = [...present].filter((name) => /^OFL-.*\.txt$/.test(name));
    assert.equal(licences.length, FONT_KEYS.length);
    assert.equal(new Set(licences).size, licences.length);
    for (const name of licences) {
        assert.match(readFileSync(path.join(fontsDir, name), 'utf8'), /SIL OPEN FONT LICENSE Version 1\.1/);
    }
    // Nothing else is shipped: no stray subsets or weights.
    const expected = new Set([...FONT_KEYS.flatMap(fontFiles), ...licences]);
    assert.deepEqual([...present].filter((name) => !expected.has(name)), []);
});

test('fonts: fonts.css declares exactly the shipped files, one family name each, and selects by class', () => {
    const css = readFileSync(path.join(templateDir, 'fonts.css'), 'utf8');
    const families = new Set();
    for (const key of FONT_KEYS) {
        const meta = FONTS[key];
        families.add(meta.family);
        for (const file of fontFiles(key)) {
            assert.ok(css.includes(`url("fonts/${file}")`), `${file} not declared`);
        }
        const faces = css.split('\n').filter((line) => line.includes(`font-family: "${meta.family}"`) && line.startsWith('@font-face'));
        assert.equal(faces.length, SUBSETS.length, meta.family);
        assert.ok(faces.every((line) => line.includes(`font-weight: ${meta.weight};`)));
        assert.match(css, new RegExp(`\\.${meta.cssClass} \\{ --hfont: "${meta.family}", (sans-serif|serif); --hweight: ${meta.weight}; \\}`));
    }
    assert.equal(families.size, FONT_KEYS.length);
    assert.equal(css.split('\n').filter((line) => line.startsWith('@font-face')).length, FONT_KEYS.length * SUBSETS.length);
});

test('fonts: a missing font file stops the service at start instead of showing fallback glyphs', () => {
    assert.throws(() => loadFontCoverages(path.join(templateDir, 'does-not-exist')), /ENOENT/);
});

test('fonts: a directory that lacks one of the shipped files is named in the error', () => {
    const dir = mkdtempSync(path.join(tmpdir(), 'fonts-missing-'));
    try {
        const files = readdirSync(path.join(templateDir, 'fonts')).filter((name) => name.endsWith('.woff2'));
        const dropped = fontFiles('inter')[0];
        for (const name of files.filter((entry) => entry !== dropped)) {
            copyFileSync(path.join(templateDir, 'fonts', name), path.join(dir, name));
        }
        assert.throws(() => loadFontCoverages(dir), new RegExp(`font file missing: ${dropped}`));
    } finally {
        rmSync(dir, { recursive: true, force: true });
    }
});
