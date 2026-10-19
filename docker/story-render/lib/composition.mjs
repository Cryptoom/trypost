import { copyFile, cp, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
export const TEMPLATE_DIR = path.join(here, '..', 'template');
export const GSAP_FILE = path.join(here, '..', 'node_modules', 'gsap', 'dist', 'gsap.min.js');

/**
 * Serializes the per-job data as a JavaScript file. `JSON.stringify` output is
 * additionally escaped so that no `<`, `>`, `&`, U+2028 or U+2029 ever appears
 * literally: the render pipeline may inline this file into an HTML document,
 * where `</script>` or `<!--` inside a string would end the script element.
 */
export const serializeData = (data) => {
    const json = JSON.stringify(data).replace(/[<>&\u{2028}\u{2029}]/gu, (char) => `\\u${char.charCodeAt(0).toString(16).padStart(4, '0')}`);

    return `window.STORY = ${json};\n`;
};

/**
 * Lays out one render job in `jobDir`: composition from the fixed template,
 * the photo under a fixed file name, the data file, GSAP and the font files,
 * all local. The headline only ever reaches the job through the data file.
 */
export const writeComposition = async (jobDir, { imageBuffer, ext, durationSeconds, headline }) => {
    if (!Number.isInteger(durationSeconds) || (ext !== 'jpg' && ext !== 'png')) {
        throw new TypeError('invalid composition parameters');
    }
    const photoName = `photo.${ext}`;

    const index = (await readFile(path.join(TEMPLATE_DIR, 'index.html'), 'utf8'))
        .replaceAll('__DURATION__', String(durationSeconds))
        .replaceAll('__PHOTO__', photoName);

    await Promise.all([
        writeFile(path.join(jobDir, 'index.html'), index),
        writeFile(path.join(jobDir, photoName), imageBuffer),
        writeFile(path.join(jobDir, 'data.js'), serializeData({ duration: durationSeconds, headline })),
        copyFile(path.join(TEMPLATE_DIR, 'story.js'), path.join(jobDir, 'story.js')),
        copyFile(path.join(TEMPLATE_DIR, 'fonts.css'), path.join(jobDir, 'fonts.css')),
        copyFile(GSAP_FILE, path.join(jobDir, 'gsap.min.js')),
        cp(path.join(TEMPLATE_DIR, 'fonts'), path.join(jobDir, 'fonts'), { recursive: true }),
    ]);
};
