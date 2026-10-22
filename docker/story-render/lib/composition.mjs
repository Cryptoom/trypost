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
 * What the composition reads, derived from a built job. Only data: no markup, no file paths from the
 * request. The logo file name is built from the detected image type.
 */
export const storyData = (job) => ({
    duration: job.duration,
    accent: job.accent,
    accentText: job.accentText,
    font: { family: job.fontMeta.family, weight: job.fontMeta.weight },
    logo: job.logo === null ? null : { file: `logo.${job.logo.ext}`, w: job.logo.w, h: job.logo.h },
    aiNotice: job.aiNotice,
    motion: job.motion,
    blocks: job.blocks,
    badge: job.badge,
    ending: job.ending,
    timeline: job.timeline,
});

/**
 * Lays out one render job in `jobDir`: composition from the fixed template, the photo and the optional
 * logo under fixed names, the data file, GSAP and the font files, all local. All user text only ever
 * reaches the job through the data file.
 */
export const writeComposition = async (jobDir, { photo, logo, job }) => {
    if (!Number.isInteger(job.duration) || (photo.ext !== 'jpg' && photo.ext !== 'png') || (logo !== null && logo.ext !== 'jpg' && logo.ext !== 'png')) {
        throw new TypeError('invalid composition parameters');
    }
    const photoName = `photo.${photo.ext}`;

    const index = (await readFile(path.join(TEMPLATE_DIR, 'index.html'), 'utf8'))
        .replaceAll('__DURATION__', String(job.duration))
        .replaceAll('__PHOTO__', photoName)
        .replaceAll('__FONTCLASS__', job.fontMeta.cssClass);

    await Promise.all([
        writeFile(path.join(jobDir, 'index.html'), index),
        writeFile(path.join(jobDir, photoName), photo.buffer),
        ...(logo === null ? [] : [writeFile(path.join(jobDir, `logo.${logo.ext}`), logo.buffer)]),
        writeFile(path.join(jobDir, 'data.js'), serializeData(storyData(job))),
        copyFile(path.join(TEMPLATE_DIR, 'story.js'), path.join(jobDir, 'story.js')),
        copyFile(path.join(TEMPLATE_DIR, 'fonts.css'), path.join(jobDir, 'fonts.css')),
        copyFile(GSAP_FILE, path.join(jobDir, 'gsap.min.js')),
        cp(path.join(TEMPLATE_DIR, 'fonts'), path.join(jobDir, 'fonts'), { recursive: true }),
    ]);
};
