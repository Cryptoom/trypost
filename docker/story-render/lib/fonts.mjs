import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import { openSync } from 'fontkit';

/**
 * The font families a story can use. A request names one of these keys; the CSS class, the family name
 * and the files all come from this table, never from the request text. Weights are the ones shipped
 * (Fontsource 5.3.0, SIL OFL 1.1, subsets latin, latin-ext, greek, cyrillic).
 */
export const FONTS = Object.freeze({
    inter: Object.freeze({ cssClass: 'font-inter', family: 'StoryInter', weight: 800, prefix: 'inter' }),
    commissioner: Object.freeze({ cssClass: 'font-commissioner', family: 'StoryCommissioner', weight: 800, prefix: 'commissioner' }),
    comfortaa: Object.freeze({ cssClass: 'font-comfortaa', family: 'StoryComfortaa', weight: 700, prefix: 'comfortaa' }),
    'noto-sans': Object.freeze({ cssClass: 'font-noto-sans', family: 'StoryNotoSans', weight: 700, prefix: 'noto-sans' }),
    'noto-serif': Object.freeze({ cssClass: 'font-noto-serif', family: 'StoryNotoSerif', weight: 700, prefix: 'noto-serif' }),
});

export const FONT_KEYS = Object.freeze(Object.keys(FONTS));

export const SUBSETS = Object.freeze(['latin', 'latin-ext', 'greek', 'cyrillic']);

/** The woff2 file names of one family. */
export const fontFiles = (key) => SUBSETS.map((subset) => `${FONTS[key].prefix}-${subset}-${FONTS[key].weight}-normal.woff2`);

const cache = new Map();

/**
 * Parses a `unicode-range` value such as `U+0301,U+0400-045F,U+04??` into [from, to] pairs.
 *
 * @param {string} value
 * @returns {Array<[number, number]>}
 */
export const parseUnicodeRange = (value) =>
    value
        .split(',')
        .map((token) => token.trim())
        .filter((token) => token !== '')
        .map((token) => {
            const match = /^U\+([0-9A-Fa-f?]{1,6})(?:-([0-9A-Fa-f]{1,6}))?$/.exec(token);
            if (match === null) {
                throw new Error(`unreadable unicode-range token: ${token}`);
            }
            if (match[1].includes('?')) {
                return [Number.parseInt(match[1].replaceAll('?', '0'), 16), Number.parseInt(match[1].replaceAll('?', 'F'), 16)];
            }
            const from = Number.parseInt(match[1], 16);

            return [from, match[2] === undefined ? from : Number.parseInt(match[2], 16)];
        });

/**
 * The `unicode-range` of every @font-face rule in the stylesheet, by font file name. The browser draws a
 * character from a face only inside that range and takes it from the fallback font otherwise.
 *
 * @param {string} css
 * @returns {Map<string, Array<[number, number]>>}
 */
export const unicodeRangesByFile = (css) => {
    const ranges = new Map();
    for (const line of css.split('\n')) {
        if (!line.startsWith('@font-face')) {
            continue;
        }
        const file = /url\("fonts\/([^"/]+)"\)/.exec(line)?.[1];
        const range = /unicode-range:\s*([^;}]+)[;}]/.exec(line)?.[1];
        if (file === undefined || range === undefined) {
            throw new Error('@font-face rule without a file or a unicode-range');
        }
        ranges.set(file, parseUnicodeRange(range));
    }

    return ranges;
};

/**
 * Per family, the set of code points the browser really draws from the shipped files: what a file has a
 * glyph for AND what the @font-face rule of that file lets the browser use (its `unicode-range`). Both are
 * read from the shipped files themselves, so the text filter can never drift from them. Characters in a
 * file but outside its range would be drawn by the fallback font, so they must not pass the filter.
 * Plain spaces are laid out by the browser.
 *
 * @param {string} fontsDir directory holding the *.woff2 files
 * @param {string} [cssFile] the stylesheet with the @font-face rules, default `fonts.css` next to the directory
 * @returns {Record<string, (codePoint: number) => boolean>}
 */
export const loadFontCoverages = (fontsDir, cssFile = path.join(fontsDir, '..', 'fonts.css')) => {
    const cacheKey = `${fontsDir}|${cssFile}`;
    if (cache.has(cacheKey)) {
        return cache.get(cacheKey);
    }
    const present = new Set(readdirSync(fontsDir));
    const rangesByFile = unicodeRangesByFile(readFileSync(cssFile, 'utf8'));
    const coverages = {};

    for (const key of FONT_KEYS) {
        const covered = new Set([0x20]);
        for (const file of fontFiles(key)) {
            if (!present.has(file)) {
                throw new Error(`font file missing: ${file}`);
            }
            const ranges = rangesByFile.get(file);
            if (ranges === undefined) {
                throw new Error(`no @font-face rule for font file: ${file}`);
            }
            for (const codePoint of openSync(path.join(fontsDir, file)).characterSet) {
                if (ranges.some(([from, to]) => codePoint >= from && codePoint <= to)) {
                    covered.add(codePoint);
                }
            }
        }
        coverages[key] = (codePoint) => covered.has(codePoint);
    }

    cache.set(cacheKey, coverages);

    return coverages;
};
