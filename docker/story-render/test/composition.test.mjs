import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { readFile, readdir, rm } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { TEMPLATE_DIR, serializeData, writeComposition } from '../lib/composition.mjs';
import { BAIT_INJECTIONS, BAIT_SCRIPTS, fakeJpeg, tmpDir } from '../testkit/helpers.mjs';

const readData = (source) => {
    const sandbox = { window: {} };
    vm.runInNewContext(source, sandbox);

    return sandbox.window.STORY;
};

test('headline injection: data file escapes <, >, & and the JS line separators', () => {
    for (const bait of [...BAIT_INJECTIONS, '<>&\u{2028}\u{2029}']) {
        const source = serializeData({ duration: 15, headline: bait });
        assert.doesNotMatch(source, /[<>&\u{2028}\u{2029}]/u, `literal breaker left in: ${JSON.stringify(bait)}`);
    }
    assert.match(serializeData({ duration: 15, headline: '<' }), /\\u003c/);
});

test('headline injection: the data file round-trips every bait headline exactly', () => {
    for (const bait of [...BAIT_INJECTIONS, ...BAIT_SCRIPTS]) {
        const data = readData(serializeData({ duration: 15, headline: bait }));
        assert.equal(data.headline, bait);
        assert.equal(data.duration, 15);
    }
});

test('headline injection: the headline never lands in the HTML, only in data.js', async () => {
    for (const bait of BAIT_INJECTIONS) {
        const dir = tmpDir();
        try {
            await writeComposition(dir, { imageBuffer: fakeJpeg(1080, 1920), ext: 'jpg', durationSeconds: 15, headline: bait });
            const html = await readFile(path.join(dir, 'index.html'), 'utf8');
            assert.ok(!html.includes('alert'), 'html must not contain the bait');
            assert.ok(!html.includes('onerror'), 'html must not contain the bait');
            assert.ok(!html.includes('x</b>'), 'html must not contain the bait');
            assert.ok(!html.includes('__DURATION__') && !html.includes('__PHOTO__'), 'placeholders must be replaced');
            assert.equal(readData(await readFile(path.join(dir, 'data.js'), 'utf8')).headline, bait);
        } finally {
            await rm(dir, { recursive: true, force: true });
        }
    }
});

test('headline injection: the template only ever sets headline words through textContent', async () => {
    const story = await readFile(path.join(TEMPLATE_DIR, 'story.js'), 'utf8');
    assert.match(story, /\.textContent = word/);
    assert.doesNotMatch(story, /innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function/);
});

test('composition: offline, no external URL in markup, styles or scripts we ship', async () => {
    const dir = tmpDir();
    try {
        await writeComposition(dir, { imageBuffer: fakeJpeg(1080, 1920), ext: 'png', durationSeconds: 15, headline: '' });
        for (const file of ['index.html', 'fonts.css', 'story.js']) {
            const source = await readFile(path.join(dir, file), 'utf8');
            assert.doesNotMatch(source, /(?:https?:)?\/\/[a-z0-9.-]+\.[a-z]{2,}/i, `${file} references an external host`);
        }
        const names = await readdir(dir);
        for (const required of ['index.html', 'data.js', 'story.js', 'fonts.css', 'gsap.min.js', 'photo.png', 'fonts']) {
            assert.ok(names.includes(required), `${required} missing`);
        }
        const fonts = await readdir(path.join(dir, 'fonts'));
        assert.ok(fonts.includes('OFL-NotoSerif.txt') && fonts.some((name) => name.includes('greek')) && fonts.some((name) => name.includes('cyrillic')));
    } finally {
        await rm(dir, { recursive: true, force: true });
    }
});

test('composition: only the two fixed photo names are ever written', async () => {
    const dir = tmpDir();
    try {
        await assert.rejects(writeComposition(dir, { imageBuffer: Buffer.alloc(1), ext: '../x', durationSeconds: 15, headline: '' }), TypeError);
        await assert.rejects(writeComposition(dir, { imageBuffer: Buffer.alloc(1), ext: 'jpg', durationSeconds: 1.5, headline: '' }), TypeError);
    } finally {
        await rm(dir, { recursive: true, force: true });
    }
});

// Real browser check: skipped where no Chrome or Chromium is installed.
const findBrowser = () =>
    [process.env.HYPERFRAMES_BROWSER_PATH, '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((candidate) => candidate && existsSync(candidate));

test('headline injection (browser): bait headlines render as text, no element or script is created', { skip: findBrowser() ? false : 'no browser installed' }, async () => {
    const { default: puppeteer } = await import('puppeteer-core');
    const browser = await puppeteer.launch({ executablePath: findBrowser(), args: ['--no-sandbox'], headless: true });
    const here = path.dirname(fileURLToPath(import.meta.url));
    const png = await readFile(path.join(here, '..', 'testkit', 'pixel.png'));
    try {
        for (const bait of [...BAIT_INJECTIONS, ...BAIT_SCRIPTS]) {
            const dir = tmpDir();
            try {
                await writeComposition(dir, { imageBuffer: png, ext: 'png', durationSeconds: 5, headline: bait.replace(/[\u{2028}\u{2029}]/gu, ' ') });
                const page = await browser.newPage();
                const dialogs = [];
                page.on('dialog', async (dialog) => {
                    dialogs.push(dialog.message());
                    await dialog.dismiss();
                });
                await page.goto(`file://${dir}/index.html`, { waitUntil: 'load' });
                await page.evaluate(() => document.fonts.ready);
                const result = await page.evaluate(() => ({
                    words: Array.from(document.querySelectorAll('#headline .wd')).map((el) => el.textContent),
                    images: document.querySelectorAll('img').length,
                    scripts: document.querySelectorAll('script').length,
                    children: document.querySelectorAll('#headline *:not(.wd)').length,
                }));
                assert.equal(result.words.join(' '), bait.replace(/[\u{2028}\u{2029}]/gu, ' ').replace(/ +/g, ' ').trim());
                assert.equal(result.images, 1, 'only the photo is an image');
                assert.equal(result.scripts, 3, 'only our three script tags');
                assert.equal(result.children, 0);
                assert.deepEqual(dialogs, []);
                await page.close();
            } finally {
                await rm(dir, { recursive: true, force: true });
            }
        }
    } finally {
        await browser.close();
    }
});
