import { readdirSync } from 'node:fs';
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
 * Per family, the set of code points its shipped files have glyphs for, read from the font files
 * themselves so the text filter can never drift from them. Plain spaces are laid out by the browser.
 *
 * @param {string} fontsDir directory holding the *.woff2 files
 * @returns {Record<string, (codePoint: number) => boolean>}
 */
export const loadFontCoverages = (fontsDir) => {
    if (cache.has(fontsDir)) {
        return cache.get(fontsDir);
    }
    const present = new Set(readdirSync(fontsDir));
    const coverages = {};

    for (const key of FONT_KEYS) {
        const covered = new Set([0x20]);
        for (const file of fontFiles(key)) {
            if (!present.has(file)) {
                throw new Error(`font file missing: ${file}`);
            }
            for (const codePoint of openSync(path.join(fontsDir, file)).characterSet) {
                covered.add(codePoint);
            }
        }
        coverages[key] = (codePoint) => covered.has(codePoint);
    }

    cache.set(fontsDir, coverages);

    return coverages;
};
