import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { readFile, readdir, rm } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { TEMPLATE_DIR, serializeData, storyData, writeComposition } from '../lib/composition.mjs';
import { FONT_KEYS, FONTS, loadFontCoverages } from '../lib/fonts.mjs';
import { EFFECTS, cameraSegments } from '../lib/motion.mjs';
import { buildJob, parseSpec } from '../lib/spec.mjs';
import { BAIT_INJECTIONS, BAIT_SCRIPTS, fakeJpeg, tmpDir, validSpec } from '../testkit/helpers.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const coverages = loadFontCoverages(path.join(TEMPLATE_DIR, 'fonts'));
const jobOf = (spec, logoInfo = null) => buildJob(parseSpec(JSON.stringify(spec)), { logoInfo, coverages });
const PNG = { buffer: Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), ext: 'png' };

const readData = (source) => {
    const sandbox = { window: {} };
    vm.runInNewContext(source, sandbox);

    return sandbox.window.STORY;
};

test('spec text injection (data file): the file escapes <, >, & and the JS line separators', () => {
    for (const bait of [...BAIT_INJECTIONS, '<>&\u{2028}\u{2029}']) {
        const source = serializeData({ duration: 15, headline: bait });
        assert.doesNotMatch(source, /[<>&\u{2028}\u{2029}]/u, `literal breaker left in: ${JSON.stringify(bait)}`);
    }
    assert.match(serializeData({ duration: 15, headline: '<' }), /\\u003c/);
});

test('spec text injection (data file): the file round-trips every bait text exactly', () => {
    for (const bait of [...BAIT_INJECTIONS, ...BAIT_SCRIPTS]) {
        const data = readData(serializeData({ duration: 15, headline: bait }));
        assert.equal(data.headline, bait);
        assert.equal(data.duration, 15);
    }
});

test('spec text injection (template): story.js sets every text through textContent or a text node and never parses markup', async () => {
    const story = await readFile(path.join(TEMPLATE_DIR, 'story.js'), 'utf8');
    assert.match(story, /span\.textContent = word\.t/);
    assert.match(story, /badge\.textContent = story\.badge/);
    assert.match(story, /ai\.textContent = story\.aiNotice/);
    assert.match(story, /createTextNode\(part\)/);
    assert.doesNotMatch(story, /innerHTML|outerHTML|insertAdjacentHTML|document\.write|eval\(|new Function|setAttribute\(\s*['"]on|\.src\s*=\s*story\.(?!logo\.file)/);
    // the only value that reaches a src is the fixed logo file name built by the service
    assert.match(story, /img\.src = story\.logo\.file/);
});

test('composition: offline, no external URL in markup, styles or scripts we ship', async () => {
    const dir = tmpDir();
    try {
        await writeComposition(dir, { photo: { buffer: fakeJpeg(1080, 1920), ext: 'png' }, logo: null, job: jobOf(validSpec({ duration: 15 })) });
        for (const file of ['index.html', 'fonts.css', 'story.js']) {
            const source = await readFile(path.join(dir, file), 'utf8');
            assert.doesNotMatch(source, /(?:https?:)?\/\/[a-z0-9.-]+\.[a-z]{2,}/i, `${file} references an external host`);
            assert.doesNotMatch(source, /https?:\/\//i);
        }
        const names = await readdir(dir);
        for (const required of ['index.html', 'data.js', 'story.js', 'fonts.css', 'gsap.min.js', 'photo.png', 'fonts']) {
            assert.ok(names.includes(required), `${required} missing`);
        }
        assert.ok(!names.some((name) => name.startsWith('logo')), 'no logo file without a logo');
        const fonts = await readdir(path.join(dir, 'fonts'));
        for (const key of FONT_KEYS) {
            assert.ok(fonts.some((name) => name.startsWith(`${FONTS[key].prefix}-greek`)) && fonts.some((name) => name.startsWith(`${FONTS[key].prefix}-cyrillic`)), key);
        }
    } finally {
        await rm(dir, { recursive: true, force: true });
    }
});

test('composition: the logo is written under a fixed name from its detected type, the font class from the catalogue', async () => {
    for (const ext of ['png', 'jpg']) {
        const dir = tmpDir();
        try {
            const job = jobOf(validSpec({ logoLayer: true, font: 'noto-serif' }), { ext, width: 300, height: 100 });
            await writeComposition(dir, { photo: { buffer: fakeJpeg(1080, 1920), ext: 'jpg' }, logo: { buffer: PNG.buffer, ext }, job });
            assert.ok((await readdir(dir)).includes(`logo.${ext}`));
            const html = await readFile(path.join(dir, 'index.html'), 'utf8');
            assert.match(html, /<div id="root" class="font-noto-serif"/);
            assert.equal(readData(await readFile(path.join(dir, 'data.js'), 'utf8')).logo.file, `logo.${ext}`);
        } finally {
            await rm(dir, { recursive: true, force: true });
        }
    }
});

test('composition: only the fixed file names are ever written', async () => {
    const dir = tmpDir();
    const job = jobOf(validSpec());
    try {
        await assert.rejects(writeComposition(dir, { photo: { buffer: Buffer.alloc(1), ext: '../x' }, logo: null, job }), TypeError);
        await assert.rejects(writeComposition(dir, { photo: { buffer: Buffer.alloc(1), ext: 'jpg' }, logo: { buffer: Buffer.alloc(1), ext: 'svg' }, job }), TypeError);
        await assert.rejects(writeComposition(dir, { photo: { buffer: Buffer.alloc(1), ext: 'jpg' }, logo: null, job: { ...job, duration: 1.5 } }), TypeError);
    } finally {
        await rm(dir, { recursive: true, force: true });
    }
});

test('composition: the data carries exactly what the template reads and nothing from the request that is not text', () => {
    const data = storyData(jobOf(validSpec({ effect: 'focus', headline: 'Hi' })));
    assert.deepEqual(Object.keys(data).sort(), ['accent', 'accentText', 'aiNotice', 'badge', 'blocks', 'duration', 'ending', 'font', 'logo', 'motion', 'timeline']);
    assert.equal(data.motion.effect, 'focus');
});

// ---- real browser checks: skipped where no Chrome or Chromium is installed ----------------------

const findBrowser = () =>
    [process.env.HYPERFRAMES_BROWSER_PATH, '/usr/bin/chromium', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find((candidate) => candidate && existsSync(candidate));

const withPage = async (spec, logoInfo, fn) => {
    const { default: puppeteer } = await import('puppeteer-core');
    const browser = await puppeteer.launch({ executablePath: findBrowser(), args: ['--no-sandbox'], headless: true });
    const dir = tmpDir();
    try {
        const png = await readFile(path.join(here, '..', 'testkit', 'pixel.png'));
        const job = jobOf(spec, logoInfo);
        await writeComposition(dir, { photo: { buffer: png, ext: 'png' }, logo: logoInfo === null ? null : { buffer: png, ext: 'png' }, job });
        const page = await browser.newPage();
        await page.setViewport({ width: 1080, height: 1920 });
        const dialogs = [];
        const errors = [];
        page.on('dialog', async (dialog) => {
            dialogs.push(dialog.message());
            await dialog.dismiss();
        });
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto(`file://${dir}/index.html`, { waitUntil: 'load' });
        await page.evaluate(() => document.fonts.ready);
        await new Promise((resolve) => setTimeout(resolve, 200));
        await fn({ page, job, dialogs, errors });
    } finally {
        await browser.close();
        await rm(dir, { recursive: true, force: true });
    }
};

const seek = (page, time) => page.evaluate((t) => { window.__timelines.main.pause(); window.__timelines.main.time(t, false); }, time);

test('spec text injection (browser): bait texts render as text, no element, script, image or dialog is created', { skip: findBrowser() ? false : 'no browser installed' }, async () => {
    for (const bait of [...BAIT_INJECTIONS, ...BAIT_SCRIPTS]) {
        const text = [...bait].slice(0, 12).join('');
        const spec = validSpec({ duration: 8, effect: 'sparkle', logoLayer: true, aiNotice: text, headline: bait.slice(0, 80), subline: text, badge: text, headlineAlt: text, sublineAlt: text, ending: { name: text, line: text } });
        await withPage(spec, { ext: 'png', width: 1, height: 1 }, async ({ page, dialogs, errors }) => {
            const result = await page.evaluate(() => ({
                images: document.querySelectorAll('img').length,
                scripts: document.querySelectorAll('script').length,
                foreign: document.querySelectorAll('#blkA *:not(.hl):not(.sub):not(.wd):not(.dot), #badge *, #end *:not(.rule):not(.name):not(.line), #ai *, #logo *:not(img)').length,
                headline: Array.from(document.querySelectorAll('#blkA .hl .wd')).map((el) => el.textContent).join(' '),
            }));
            assert.equal(result.images, 3, 'two photo copies and the logo, nothing else');
            assert.equal(result.scripts, 3, 'only our three script tags: gsap, data and story');
            assert.equal(result.foreign, 0);
            assert.equal(result.headline.replace(/\s+/g, ' ').trim(), bait.slice(0, 80).replace(/[\u{2028}\u{2029}]/gu, ' ').replace(/\s+/g, ' ').trim());
            assert.deepEqual(dialogs, []);
            assert.deepEqual(errors, []);
        });
    }
});

test('composition (browser): text, badge, ending, logo and notice stay inside the 60 px side margin at every stage', { skip: findBrowser() ? false : 'no browser installed' }, async () => {
    const longWord = 'W'.repeat(80);
    const specs = [
        validSpec({ duration: 8, logoLayer: true, aiNotice: 'x'.repeat(40), headline: 'Camping time with *Peppa* and the whole family tonight', subline: 'Now in store · Paphos', badge: 'WWWWWWWWWWWW', headlineAlt: 'Ώρα για κάμπινγκ με την *Peppa* και όλη την οικογένεια απόψε', sublineAlt: 'Τώρα στο κατάστημα · Πάφος', ending: { name: 'N'.repeat(40), line: 'L'.repeat(60) } }),
        validSpec({ duration: 15, font: 'comfortaa', logoLayer: true, aiNotice: 'AI-generated image', headline: longWord, subline: 'S'.repeat(60), badge: 'ПРОБУЖДАЕТ', ending: { name: 'ПРОБУЖДАЕТСЯ'.repeat(3), line: 'Ελληνικά Παιχνίδια' } }),
    ];
    for (const spec of specs) {
        await withPage(spec, { ext: 'png', width: 4000, height: 1528 }, async ({ page, job }) => {
            const stages = [0.3, job.duration * 0.25, job.duration * 0.45, job.duration * 0.7, job.duration - 0.2];
            for (const time of stages) {
                await seek(page, time);
                const boxes = await page.evaluate(() => {
                    const visible = (el) => {
                        const style = getComputedStyle(el);
                        let node = el;
                        let opacity = 1;
                        while (node && node.id !== 'root') {
                            opacity *= Number(getComputedStyle(node).opacity);
                            node = node.parentElement;
                        }

                        return style.display !== 'none' && opacity > 0.05;
                    };
                    const rect = (el) => { const r = el.getBoundingClientRect(); return { left: r.left, right: r.right, top: r.top, bottom: r.bottom }; };

                    return Array.from(document.querySelectorAll('#blkA .hl .wd, #blkA .sub, #blkB .hl .wd, #blkB .sub, #badge, #end .name, #end .line, #logo, #ai'))
                        .filter(visible)
                        .map((el) => ({ id: el.id || el.className, ...rect(el), widthScroll: el.scrollWidth, widthClient: el.clientWidth }));
                });
                for (const box of boxes) {
                    // The badge is rotated by 8 degrees, give it a few pixels for the corner.
                    const slack = box.id === 'badge' ? 12 : 0.5;
                    assert.ok(box.left >= 60 - slack, `${box.id} at ${time}s: left ${box.left}`);
                    assert.ok(box.right <= 1020 + slack, `${box.id} at ${time}s: right ${box.right}`);
                }
                const ending = boxes.filter((box) => box.id === 'name' || box.id === 'line');
                for (const box of ending) {
                    assert.ok(box.bottom <= 1580 + 1, `ending ${box.id} at ${time}s reaches y=${box.bottom}`);
                }
            }
        });
    }
});

test('composition (browser): logo and AI notice sit still at the agreed spot while the photo moves', { skip: findBrowser() ? false : 'no browser installed' }, async () => {
    for (const effect of EFFECTS) {
        const spec = validSpec({ duration: 8, effect, logoLayer: true, aiNotice: 'AI-generated image', headline: 'Hello' });
        await withPage(spec, { ext: 'png', width: 4000, height: 1528 }, async ({ page }) => {
            const read = () => page.evaluate(() => {
                const r = (id) => { const b = document.getElementById(id).getBoundingClientRect(); return [b.left, b.top, b.width, b.height].map((n) => Math.round(n * 100) / 100); };

                return { logo: r('logo'), ai: r('ai'), photo: r('photo') };
            });
            await seek(page, 0);
            const first = await read();
            await seek(page, 4);
            const middle = await read();
            await seek(page, 7.9);
            const last = await read();
            assert.deepEqual(middle.logo, first.logo, `${effect}: logo moved`);
            assert.deepEqual(last.logo, first.logo, `${effect}: logo moved`);
            assert.deepEqual(middle.ai, first.ai, `${effect}: notice moved`);
            assert.deepEqual(last.ai, first.ai, `${effect}: notice moved`);
            assert.notDeepEqual(middle.photo, first.photo, `${effect}: the photo must move`);
            // logo card: 68 px high, centred, top at y=155; notice starts at x=70, y=1080
            assert.equal(first.logo[1], 155);
            assert.equal(first.logo[3], 68);
            assert.ok(Math.abs(first.logo[0] + first.logo[2] / 2 - 540) < 0.5);
            assert.equal(first.ai[0], 70);
            assert.equal(first.ai[1], 1080);
            assert.ok(first.ai[1] + first.ai[3] <= 1580);
        });
    }
});

test('composition (browser): the page replays the motion table, scale, tilt and shift match at start, middle and end', { skip: findBrowser() ? false : 'no browser installed' }, async () => {
    const { gsap } = await import('gsap');
    for (const effect of EFFECTS) {
        for (const strong of [true, false]) {
            const spec = validSpec({ duration: 8, effect, logoLayer: strong, aiNotice: strong ? 'AI-generated image' : null });
            await withPage(spec, strong ? { ext: 'png', width: 100, height: 40 } : null, async ({ page }) => {
                const segments = cameraSegments(effect, strong, 8);
                for (const time of [0, 2, 4, 6, 8]) {
                    const segment = segments.find((candidate) => time <= candidate.at + candidate.duration + 1e-9) ?? segments.at(-1);
                    const progress = Math.min(1, Math.max(0, (time - segment.at) / segment.duration));
                    const eased = gsap.parseEase(segment.ease)(progress);
                    const expected = Object.fromEntries(['scale', 'rotation', 'x', 'y'].map((key) => [key, segment.from[key] + (segment.to[key] - segment.from[key]) * eased]));
                    await seek(page, time);
                    const actual = await page.evaluate(() => ({ scale: gsap.getProperty('#move', 'scale'), rotation: gsap.getProperty('#move', 'rotation'), x: gsap.getProperty('#move', 'x'), y: gsap.getProperty('#move', 'y') }));
                    for (const key of Object.keys(expected)) {
                        assert.ok(Math.abs(actual[key] - expected[key]) < 1e-3, `${effect} ${strong ? 'strong' : 'calm'} ${key} at ${time}s: ${actual[key]} vs ${expected[key]}`);
                    }
                }
            });
        }
    }
});

test('composition (browser): the whole ending block, accent line included, sits inside the full-strength part of the lower veil', { skip: findBrowser() ? false : 'no browser installed' }, async () => {
    const endings = [
        { name: 'PlayCraft', line: 'Paphos' },
        { name: null, line: 'Only a line' },
        { name: 'N'.repeat(40), line: 'L'.repeat(60) },
        { name: 'ПРОБУЖДАЕТСЯ'.repeat(3), line: 'Ελληνικά Παιχνίδια και ΠΟΛΛΑ ΑΚΟΜΑ ΛΟΓΙΑ ΓΙΑ ΔΥΟ' },
    ];
    for (const ending of endings) {
        for (const font of ['inter', 'comfortaa', 'noto-serif']) {
            await withPage(validSpec({ duration: 8, font, ending }), null, async ({ page, job }) => {
                await seek(page, job.duration - 0.2);
                const geometry = await page.evaluate(() => {
                    const veil = document.getElementById('scrimBottom');
                    const end = document.getElementById('end');
                    const stops = [...getComputedStyle(veil).backgroundImage.matchAll(/rgba\(10, 42, 61, ([0-9.]+)\) ([0-9.]+)(px|%)/g)].map((match) => ({ alpha: Number(match[1]), at: Number(match[2]), unit: match[3] }));
                    const veilBox = veil.getBoundingClientRect();
                    const endBox = end.getBoundingClientRect();
                    const rule = end.querySelector('.rule').getBoundingClientRect();

                    return { gradient: getComputedStyle(veil).backgroundImage, veilTop: veilBox.top, veilBottom: veilBox.bottom, stops, endTop: endBox.top, endBottom: endBox.bottom, ruleTop: rule.top };
                });
                assert.match(geometry.gradient, /^linear-gradient\((to bottom, )?rgba/, 'the veil runs from transparent at its top to full strength towards the bottom');
                const full = geometry.stops.find((stop) => stop.alpha === 0.8 && stop.unit === 'px');
                assert.ok(full, `a full-strength stop in px: ${JSON.stringify(geometry.stops)}`);
                assert.ok(geometry.veilBottom >= 1920 - 0.5, 'the veil runs to the bottom edge');
                assert.ok(geometry.veilTop + full.at <= geometry.ruleTop - 39, `full strength starts at ${geometry.veilTop + full.at}, the block (rule) at ${geometry.ruleTop}`);
                assert.ok(geometry.endBottom <= 1540 + 1, 'the block still ends at y=1540');
            });
        }
    }
});
