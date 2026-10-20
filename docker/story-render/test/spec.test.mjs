import assert from 'node:assert/strict';
import { readFile, rm } from 'node:fs/promises';
import path from 'node:path';
import test from 'node:test';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { serializeData, storyData, writeComposition } from '../lib/composition.mjs';
import { loadFontCoverages } from '../lib/fonts.mjs';
import { amplitude } from '../lib/motion.mjs';
import { normalizeText, sanitizeText } from '../lib/text.mjs';
import { AI_NOTICE_FALLBACK, buildJob, logoSize, parseSpec, TEXT_LIMITS } from '../lib/spec.mjs';
import { LIMITS, ValidationError } from '../lib/validate.mjs';
import { BAIT_INJECTIONS, BAIT_SCRIPTS, fakeJpeg, tmpDir, validSpec } from '../testkit/helpers.mjs';

const coverages = loadFontCoverages(path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'template', 'fonts'));
const parse = (spec) => parseSpec(typeof spec === 'string' ? spec : JSON.stringify(spec));
const build = (spec, logoInfo = null) => buildJob(parse(spec), { logoInfo, coverages });
const LOGO = { ext: 'png', width: 4000, height: 1528 };

const rejects = (fn, code, field) =>
    assert.throws(fn, (error) => error instanceof ValidationError && error.code === code && (field === undefined || error.field === field), `expected ${code}`);

const readData = (source) => {
    const sandbox = { window: {} };
    vm.runInNewContext(source, sandbox);

    return sandbox.window.STORY;
};

test('spec: a complete valid spec is accepted', () => {
    const spec = parse(validSpec({ duration: 15, effect: 'snap', font: 'noto-serif', logoLayer: true, aiNotice: 'AI-generated image', headline: 'Camping *Peppa*', subline: 'Now in store', badge: '-20%', headlineAlt: 'Ώρα', sublineAlt: 'Τώρα', ending: { name: 'PlayCraft', line: 'Paphos' } }));
    assert.equal(spec.duration, 15);
    assert.equal(spec.ending.name, 'PlayCraft');
});

test('spec: optional texts may be absent, null or empty', () => {
    const spec = parse({ duration: 8, effect: 'drift', font: 'inter', accent: '#FDB31B', logoLayer: false });
    assert.equal(spec.headline, null);
    assert.equal(spec.ending, null);
    const job = build(validSpec({ duration: 8, headline: '', subline: '   ', badge: '', headlineAlt: null, ending: { name: '', line: null } }));
    assert.deepEqual(job.blocks, []);
    assert.equal(job.badge, null);
    assert.equal(job.ending, null);
});

test('spec: invalid JSON, non-objects and oversized specs are rejected', () => {
    rejects(() => parseSpec('{'), 'spec_invalid_json');
    rejects(() => parseSpec('[]'), 'spec_invalid_json');
    rejects(() => parseSpec('null'), 'spec_invalid_json');
    rejects(() => parseSpec('"text"'), 'spec_invalid_json');
    rejects(() => parseSpec(undefined), 'spec_too_large');
    rejects(() => parseSpec(JSON.stringify(validSpec({ headline: 'x'.repeat(LIMITS.maxSpecBytes) }))), 'spec_too_large');
});

test('spec: unknown keys are rejected at the top level and inside ending', () => {
    rejects(() => parse({ ...validSpec(), extra: 1 }), 'spec_unknown_key', null);
    rejects(() => parse({ ...validSpec(), ending: { name: 'a', place: 'b' } }), 'spec_unknown_key', null);
    rejects(() => parse({ ...validSpec(), photoUrl: 'http://x' }), 'spec_unknown_key');
});

test('spec: prototype keys are rejected, also nested, and pollute nothing', () => {
    for (const key of ['__proto__', 'constructor', 'prototype']) {
        rejects(() => parseSpec(`{"duration":8,"effect":"drift","font":"inter","accent":"#FDB31B","logoLayer":false,"${key}":{"polluted":true}}`), 'spec_unknown_key');
        rejects(() => parseSpec(`{"duration":8,"effect":"drift","font":"inter","accent":"#FDB31B","logoLayer":false,"ending":{"${key}":{"polluted":true}}}`), 'spec_unknown_key');
    }
    assert.equal({}.polluted, undefined);
});

test('spec: every required key must be present', () => {
    for (const key of ['duration', 'effect', 'font', 'accent', 'logoLayer']) {
        const spec = validSpec();
        delete spec[key];
        rejects(() => parse(spec), 'spec_missing_key', key);
    }
});

test('spec: duration is a whole number from 3 to 20 and only a number', () => {
    for (const duration of [3, 20]) {
        assert.equal(parse(validSpec({ duration })).duration, duration);
    }
    for (const bad of [2, 21, 0, -1, 3.5, '8', null, true, [8], 1e9, Number.NaN]) {
        rejects(() => parse(JSON.stringify({ ...validSpec(), duration: bad })), 'spec_invalid_duration', 'duration');
    }
});

test('spec: effect and font must come from the lists', () => {
    for (const effect of ['drift', 'focus', 'snap', 'sparkle']) {
        assert.equal(parse(validSpec({ effect })).effect, effect);
    }
    for (const bad of ['wobble', 'Drift', '', 'drift ', null, 1, '__proto__', 'constructor']) {
        rejects(() => parse({ ...validSpec(), effect: bad }), 'spec_invalid_effect');
    }
    for (const font of ['inter', 'commissioner', 'comfortaa', 'noto-sans', 'noto-serif']) {
        assert.equal(parse(validSpec({ font })).font, font);
    }
    for (const bad of ['Inter', 'arial', 'inter; x', '../fonts', '', null, 'toString', 'noto sans']) {
        rejects(() => parse({ ...validSpec(), font: bad }), 'spec_invalid_font');
    }
});

test('spec: the accent colour is anchored #RRGGBB in upper case, nothing else', () => {
    assert.equal(parse(validSpec({ accent: '#0A2A3D' })).accent, '#0A2A3D');
    for (const bad of ['#fdb31b', '#FDB31', '#FDB31B0', '#FDB31B\n', '\n#FDB31B', ' #FDB31B', '#FDB31BFF', 'FDB31B', '#GGGGGG', 'red', '#FDB31B"};alert(1);//', null, 255]) {
        rejects(() => parse({ ...validSpec(), accent: bad }), 'spec_invalid_accent');
    }
});

test('spec: boolean and text fields have to have the right type', () => {
    rejects(() => parse({ ...validSpec(), logoLayer: 'true' }), 'spec_invalid_type', 'logoLayer');
    rejects(() => parse({ ...validSpec(), logoLayer: 1 }), 'spec_invalid_type', 'logoLayer');
    for (const field of ['headline', 'subline', 'badge', 'headlineAlt', 'sublineAlt', 'aiNotice']) {
        for (const bad of [5, true, ['a'], { a: 1 }]) {
            rejects(() => parse({ ...validSpec(), [field]: bad }), 'spec_invalid_type', field);
        }
    }
    rejects(() => parse({ ...validSpec(), ending: 'text' }), 'spec_invalid_type', 'ending');
    rejects(() => parse({ ...validSpec(), ending: [] }), 'spec_invalid_type', 'ending');
    rejects(() => parse({ ...validSpec(), ending: { name: 5 } }), 'spec_invalid_type', 'ending.name');
});

test('spec: text limits are exact for every field, counted in characters', () => {
    const limits = { headline: 80, subline: 60, badge: 12, headlineAlt: 80, sublineAlt: 60, aiNotice: 40 };
    for (const [field, limit] of Object.entries(limits)) {
        assert.equal(TEXT_LIMITS[field], limit);
        assert.doesNotThrow(() => parse({ ...validSpec(), [field]: 'ä'.repeat(limit) }), `${field} at the limit`);
        rejects(() => parse({ ...validSpec(), [field]: 'ä'.repeat(limit + 1) }), 'spec_text_too_long', field);
    }
    assert.doesNotThrow(() => parse({ ...validSpec(), ending: { name: 'n'.repeat(40), line: 'l'.repeat(60) } }));
    rejects(() => parse({ ...validSpec(), ending: { name: 'n'.repeat(41) } }), 'spec_text_too_long', 'ending.name');
    rejects(() => parse({ ...validSpec(), ending: { line: 'l'.repeat(61) } }), 'spec_text_too_long', 'ending.line');
});

test('spec: accent marks do not count towards the headline limit', () => {
    assert.doesNotThrow(() => parse({ ...validSpec(), headline: `*${'a'.repeat(78)}*` }));
    assert.doesNotThrow(() => parse({ ...validSpec(), headlineAlt: `*${'a'.repeat(78)}*` }));
    rejects(() => parse({ ...validSpec(), headline: `*${'a'.repeat(81)}*` }), 'spec_text_too_long', 'headline');
    assert.doesNotThrow(() => parse({ ...validSpec(), headline: `*${'a'.repeat(80)}*` }), 'exactly 80 characters besides the marks');
});

test('spec: logo layer needs a logo, and a logo needs the layer', () => {
    rejects(() => build(validSpec({ logoLayer: true })), 'logo_required');
    rejects(() => build(validSpec({ logoLayer: false }), LOGO), 'logo_unexpected');
    assert.equal(build(validSpec({ logoLayer: true }), LOGO).logo.ext, 'png');
    assert.equal(build(validSpec({ logoLayer: false })).logo, null);
});

test('spec: the logo is 48 px high and at most 480 px wide, so the card is at most 520 x 68', () => {
    assert.deepEqual(logoSize({ width: 4000, height: 1528 }), { w: 126, h: 48 });
    assert.deepEqual(logoSize({ width: 100, height: 100 }), { w: 48, h: 48 });
    assert.deepEqual(logoSize({ width: 2000, height: 100 }), { w: 480, h: 24 }, 'a very wide logo is shrunk proportionally');
    assert.deepEqual(logoSize({ width: 2000, height: 1 }), { w: 480, h: 1 });
    for (const [width, height] of [[1, 2000], [2000, 2000], [2000, 100], [333, 77]]) {
        const { w, h } = logoSize({ width, height });
        assert.ok(h <= 48 && w <= 480 && w >= 1 && h >= 1);
        assert.ok(w + 40 <= 520 && h + 20 <= 68);
    }
});

test('spec: an AI notice without a letter the font can draw falls back to the English notice instead of vanishing', () => {
    const cases = [
        ['صورة أُنشئت بالذكاء الاصطناعي', AI_NOTICE_FALLBACK, true],
        ['🤖✨', AI_NOTICE_FALLBACK, true],
        ['\u0301', AI_NOTICE_FALLBACK, true],
        ['\u0301\u0300', AI_NOTICE_FALLBACK, true],
        ['日本語', AI_NOTICE_FALLBACK, true],
        ['', AI_NOTICE_FALLBACK, true],
        ['AI生成画像', AI_NOTICE_FALLBACK, true],
        ['KI-generiertes Bild', 'KI-generiertes Bild', false],
        ['Εικόνα AI', 'Εικόνα AI', false],
    ];
    for (const [text, shown, fallback] of cases) {
        const job = build(validSpec({ logoLayer: true, aiNotice: text }), LOGO);
        assert.equal(job.aiNotice, shown, JSON.stringify(text));
        assert.equal(job.aiNoticeFallback, fallback, JSON.stringify(text));
        assert.equal(job.strong, true, `the notice is shown, so motion is strong: ${JSON.stringify(text)}`);
    }

    const off = build(validSpec({ logoLayer: true, aiNotice: null }), LOGO);
    assert.equal(off.aiNotice, null, 'a notice that was not requested stays off');
    assert.equal(off.aiNoticeFallback, false);
    assert.ok(/\p{L}/u.test(AI_NOTICE_FALLBACK) && sanitizeText(AI_NOTICE_FALLBACK, coverages.inter) === AI_NOTICE_FALLBACK, 'the fallback itself is drawable');
});

test('spec: an AI notice stays complete in every font or uses the controlled English fallback', () => {
    for (const font of Object.keys(coverages)) {
        for (const text of ['Ảnh do AI tạo', 'ἀἁἂ ἄρτος', 'A ạạạạ']) {
            const job = build(validSpec({ font, aiNotice: text }));
            assert.equal(job.aiNotice, AI_NOTICE_FALLBACK, `${font}: ${text}`);
            assert.equal(job.aiNoticeFallback, true);
        }
        for (const text of ['KI-generiertes Bild', 'Εικόνα AI', 'ИИ-изображение']) {
            assert.equal(build(validSpec({ font, aiNotice: text })).aiNotice, text, `${font}: ${text}`);
        }
        assert.equal(build(validSpec({ font, aiNotice: '  Cre\u0301e\u0301\tpar\u00a0AI  ' })).aiNotice, 'Créé par AI');
        assert.equal(build(validSpec({ font, aiNotice: 'AI\u200B image' })).aiNotice, 'AI image');
    }
});

test('spec: strong motion needs the logo layer AND the AI notice, otherwise every effect stays calm', () => {
    for (const effect of ['drift', 'focus', 'snap', 'sparkle']) {
        const strong = build(validSpec({ effect, logoLayer: true, aiNotice: 'AI-generated image' }), LOGO);
        assert.equal(strong.strong, true);
        assert.ok(amplitude(strong.motion.segments).scale > 1.02, `${effect} strong must be strong`);

        const calmCases = [
            validSpec({ effect, logoLayer: true, aiNotice: null }),
            validSpec({ effect, logoLayer: false, aiNotice: 'AI-generated image' }),
            validSpec({ effect, logoLayer: false, aiNotice: '日本語' }),
            validSpec({ effect, logoLayer: false, aiNotice: null }),
        ];
        for (const spec of calmCases) {
            const job = build(spec, spec.logoLayer ? LOGO : null);
            assert.equal(job.strong, false, `${effect} ${JSON.stringify(spec.aiNotice)} logo=${spec.logoLayer}`);
            const peak = amplitude(job.motion.segments);
            assert.ok(peak.scale <= 1.02 + 1e-9 && peak.tilt === 0 && peak.driftX === 0 && peak.driftY === 0);
        }
    }
});

test('spec: on a clip shorter than 6 seconds the language swap is dropped, shorter than 4 also the ending', () => {
    const full = { headline: 'One', subline: 'a', headlineAlt: 'Eins', sublineAlt: 'b', ending: { name: 'Name', line: 'Line' } };
    assert.equal(build(validSpec({ duration: 6, ...full })).blocks.length, 2);
    assert.equal(build(validSpec({ duration: 5, ...full })).blocks.length, 1);
    assert.equal(build(validSpec({ duration: 5, ...full })).blocks[0].headline[0].t, 'One');
    assert.notEqual(build(validSpec({ duration: 4, ...full })).ending, null);
    assert.equal(build(validSpec({ duration: 3, ...full })).ending, null);
    assert.equal(build(validSpec({ duration: 3, ...full })).timeline.ending, null);
});

test('spec: empty or unusable elements are left out, a second language alone is shown on its own', () => {
    const job = build(validSpec({ duration: 8, headline: '🔥', headlineAlt: 'Zweite Sprache', sublineAlt: 'Zeile' }));
    assert.equal(job.blocks.length, 1);
    assert.equal(job.blocks[0].headline[0].t, 'Zweite');
    assert.equal(build(validSpec({ badge: '🔥' })).badge, null);
});

test('spec: the accent text colour is dark on light accents and white on dark ones', () => {
    assert.equal(build(validSpec({ accent: '#FDB31B' })).accentText, '#0A2A3D');
    assert.equal(build(validSpec({ accent: '#FFFFFF' })).accentText, '#0A2A3D');
    assert.equal(build(validSpec({ accent: '#0A2A3D' })).accentText, '#FFFFFF');
    assert.equal(build(validSpec({ accent: '#000080' })).accentText, '#FFFFFF');
});

// ---- text injection: one test per text field --------------------------------------------------

const FIELD_SPECS = {
    headline: (text) => validSpec({ headline: text }),
    subline: (text) => validSpec({ subline: text }),
    badge: (text) => validSpec({ badge: text }),
    headlineAlt: (text) => validSpec({ duration: 8, headline: 'x', headlineAlt: text }),
    sublineAlt: (text) => validSpec({ duration: 8, headline: 'x', sublineAlt: text }),
    aiNotice: (text) => validSpec({ aiNotice: text }),
    'ending.name': (text) => validSpec({ duration: 8, ending: { name: text } }),
    'ending.line': (text) => validSpec({ duration: 8, ending: { line: text } }),
};

// What a field's text must look like in the data after sanitizing, read back out of the executed data file.
const shownText = (field, data) => {
    switch (field) {
        case 'headline': return data.blocks[0].headline.map((word) => word.t).join(' ');
        case 'subline': return data.blocks[0].subline.join(' · ');
        case 'badge': return data.badge;
        case 'headlineAlt': return data.blocks[1].headline.map((word) => word.t).join(' ');
        case 'sublineAlt': return data.blocks[1].subline.join(' · ');
        case 'aiNotice': return data.aiNotice;
        case 'ending.name': return data.ending.name;
        case 'ending.line': return data.ending.line;
        default: throw new Error(field);
    }
};

for (const [field, makeSpec] of Object.entries(FIELD_SPECS)) {
    test(`spec text injection: ${field}`, async () => {
        const limit = TEXT_LIMITS[field];
        for (const bait of [...BAIT_INJECTIONS, ...BAIT_SCRIPTS, '*a*</script>', '"};alert(1);//']) {
            const text = [...bait].slice(0, limit).join('');
            const spec = makeSpec(text);
            const job = build(spec);

            // 1. the data file contains no literal breaker and round-trips the sanitized text exactly
            const source = serializeData(storyData(job));
            assert.doesNotMatch(source, /[<>&\u{2028}\u{2029}]/u, `literal breaker in data file for ${JSON.stringify(text)}`);
            const data = readData(source);
            // only the two headlines know accent marks; everywhere else a star is an ordinary character
            const sanitized = sanitizeText(field.startsWith('headline') ? text.replaceAll('*', '') : text, coverages.inter);
            // the AI notice never vanishes: without a drawable letter the English notice stands in
            const expected = field === 'aiNotice' && (!/\p{L}/u.test(sanitized) || sanitized !== normalizeText(text)) ? AI_NOTICE_FALLBACK : sanitized;
            const shown = shownText(field, data);
            assert.equal(shown.replaceAll(' · ', ' '), expected.replaceAll(' · ', ' ').trim().replace(/^·|·$/g, '').trim(), `text of ${field} changed on the way for ${JSON.stringify(text)}`);

            // 2. the markup file never contains any of it
            const dir = tmpDir();
            try {
                await writeComposition(dir, { photo: { buffer: fakeJpeg(1080, 1920), ext: 'jpg' }, logo: null, job });
                const html = await readFile(path.join(dir, 'index.html'), 'utf8');
                for (const marker of ['alert', 'onerror', 'x</b>', 'src=x']) {
                    assert.ok(!html.includes(marker), `${marker} reached index.html`);
                }
                assert.equal((html.match(/<img/g) ?? []).length, 3, 'only the two photo copies and the logo are images');
                assert.ok(!html.includes('__FONTCLASS__') && !html.includes('__PHOTO__') && !html.includes('__DURATION__'));
                assert.equal((html.match(/<script/g) ?? []).length, 3, 'only our three script tags');
            } finally {
                await rm(dir, { recursive: true, force: true });
            }
        }
    });
}

test('spec text injection (data file): a bait in every field at once leaves the file parseable and inert', () => {
    const bait = '</script><img src=x onerror=alert(1)>';
    const spec = validSpec({ duration: 8, headline: bait.slice(0, 80), subline: bait, badge: '</script>', headlineAlt: bait, sublineAlt: bait, aiNotice: bait, ending: { name: bait, line: bait } });
    const source = serializeData(storyData(build(spec)));
    assert.doesNotMatch(source, /[<>&\u{2028}\u{2029}]/u);
    const data = readData(source);
    assert.equal(typeof data.motion.segments[0].duration, 'number');
    assert.equal(data.accent, '#FDB31B');
});

test('spec text injection (data file): numbers and names in the data come from tables, never from request text', () => {
    const data = storyData(build(validSpec({ font: 'comfortaa', effect: 'sparkle' })));
    assert.deepEqual(data.font, { family: 'StoryComfortaa', weight: 700 });
    assert.equal(data.logo, null);
    const logoData = storyData(build(validSpec({ logoLayer: true }), { ext: 'jpg', width: 300, height: 100 }));
    assert.deepEqual(logoData.logo, { file: 'logo.jpg', w: 144, h: 48 });
});
