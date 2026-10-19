import { readdirSync } from 'node:fs';
import path from 'node:path';
import { openSync } from 'fontkit';

/**
 * Builds the set of code points the shipped headline font has glyphs for, read
 * from the font files themselves so the sanitizer can never drift from them.
 *
 * @param {string} fontsDir directory holding the *.woff2 subset files
 * @returns {(codePoint: number) => boolean}
 */
export const loadFontCoverage = (fontsDir) => {
    const covered = new Set();

    for (const file of readdirSync(fontsDir).filter((name) => name.endsWith('.woff2'))) {
        const font = openSync(path.join(fontsDir, file));
        for (const codePoint of font.characterSet) {
            covered.add(codePoint);
        }
    }

    // Plain spaces are laid out by the browser, not drawn from a glyph.
    covered.add(0x20);

    return (codePoint) => covered.has(codePoint);
};
