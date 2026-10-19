import assert from 'node:assert/strict';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { loadFontCoverage } from '../lib/fonts.mjs';
import { storyVideoProblem } from '../lib/probe.mjs';
import { decodeHeadlineHeader, inspectImage, parseDuration, sanitizeHeadline, ValidationError } from '../lib/validate.mjs';
import { BAIT_SCRIPTS, encodeHeadline, fakeJpeg, fakePng } from '../testkit/helpers.mjs';

const fontsDir = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'template', 'fonts');
const isCovered = loadFontCoverage(fontsDir);

const rejects = (fn, code, status) =>
    assert.throws(fn, (error) => error instanceof ValidationError && error.code === code && (status === undefined || error.status === status));

test('image: accepts JPEG and PNG by magic bytes and reads the size', () => {
    assert.deepEqual(inspectImage(fakeJpeg(1080, 1920)), { type: 'jpeg', ext: 'jpg', width: 1080, height: 1920 });
    assert.deepEqual(inspectImage(fakePng(1080, 1920)), { type: 'png', ext: 'png', width: 1080, height: 1920 });
});

test('image: rejects everything that is not JPEG or PNG, whatever a Content-Type would claim', () => {
    for (const body of ['<svg xmlns="http://www.w3.org/2000/svg"/>', 'GIF89a....', 'RIFF....WEBP', '<html></html>', '']) {
        rejects(() => inspectImage(Buffer.from(body)), 'unsupported_image_type', 415);
    }
});

test('image: rejects truncated headers and oversize dimensions', () => {
    rejects(() => inspectImage(Buffer.from([0xff, 0xd8, 0xff, 0xe0, 0x00, 0x10])), 'invalid_image');
    rejects(() => inspectImage(fakePng(4097, 100)), 'image_dimensions_too_large', 413);
    rejects(() => inspectImage(fakeJpeg(100, 4097)), 'image_dimensions_too_large', 413);
    assert.equal(inspectImage(fakeJpeg(4096, 4096)).width, 4096);
    rejects(() => inspectImage(fakePng(0, 10)), 'invalid_image');
});

test('duration: only whole seconds from 3 to 20', () => {
    assert.equal(parseDuration('3'), 3);
    assert.equal(parseDuration('20'), 20);
    for (const bad of ['2', '21', '', 'abc', '15.5', '-5', '015', undefined, '1e1']) {
        rejects(() => parseDuration(bad), 'invalid_duration');
    }
});

test('headline header: absent is null, bad encodings and over 80 characters are rejected', () => {
    assert.equal(decodeHeadlineHeader(undefined), null);
    assert.equal(decodeHeadlineHeader(encodeHeadline('Hallo')), 'Hallo');
    assert.equal(decodeHeadlineHeader(encodeHeadline('W'.repeat(80))).length, 80);
    rejects(() => decodeHeadlineHeader(encodeHeadline('W'.repeat(81))), 'headline_too_long');
    rejects(() => decodeHeadlineHeader('not base64 !!'), 'invalid_headline');
    rejects(() => decodeHeadlineHeader(Buffer.from([0xff, 0xfe, 0xfd]).toString('base64url')), 'invalid_headline');
});

test('headline sanitizer: control characters, emoji and unsupported scripts are removed', () => {
    assert.equal(sanitizeHeadline('Hi\u0000 \u0007there\u{200b}', isCovered), 'Hi there');
    assert.equal(sanitizeHeadline('Sale 🔥🔥 now 👍🏽 ❤️', isCovered), 'Sale now');
    assert.equal(sanitizeHeadline('  a \t\n  b\u{00a0}\u{2028}c  ', isCovered), 'a b c');
    assert.equal(sanitizeHeadline('日本語 text', isCovered), 'text');
    assert.equal(sanitizeHeadline('مرحبا', isCovered), '');
});

test('headline sanitizer: copyright, registered and trademark signs are kept, real emoji are not', () => {
    assert.equal(sanitizeHeadline('Maisto\u{00ae} GT3', isCovered), 'Maisto\u{00ae} GT3');
    assert.equal(sanitizeHeadline('PlayCraft\u{2122} Sale', isCovered), 'PlayCraft\u{2122} Sale');
    assert.equal(sanitizeHeadline('\u{00a9} 2026', isCovered), '\u{00a9} 2026');
    assert.equal(sanitizeHeadline('Sale 🔥 \u{2122}\u{fe0f}', isCovered), 'Sale \u{2122}');
});

test('headline sanitizer: Latin, Greek and Cyrillic bait headlines survive unchanged (font coverage)', () => {
    for (const bait of BAIT_SCRIPTS) {
        assert.equal(sanitizeHeadline(bait, isCovered), bait);
    }
});

test('headline sanitizer: markup characters stay as plain text characters', () => {
    const bait = '</script><img src=x onerror=alert(1)>';
    assert.equal(sanitizeHeadline(bait, isCovered), bait);
});

test('output guard: accepts exactly one h264 yuv420p 1080x1920 30 fps video without audio of the right length', () => {
    const good = { streams: [{ codec_type: 'video', codec_name: 'h264', pix_fmt: 'yuv420p', width: 1080, height: 1920, r_frame_rate: '30/1' }], format: { duration: '15.03' } };
    assert.equal(storyVideoProblem(good, 15), null);

    const withStream = (patch) => ({ ...good, streams: [{ ...good.streams[0], ...patch }] });
    assert.equal(storyVideoProblem(withStream({ codec_name: 'hevc' }), 15), 'codec');
    assert.equal(storyVideoProblem(withStream({ pix_fmt: 'yuv444p' }), 15), 'pixel_format');
    assert.equal(storyVideoProblem(withStream({ width: 1080, height: 1080 }), 15), 'dimensions');
    assert.equal(storyVideoProblem(withStream({ r_frame_rate: '25/1' }), 15), 'frame_rate');
    assert.equal(storyVideoProblem({ ...good, streams: [...good.streams, { codec_type: 'audio', codec_name: 'aac' }] }, 15), 'unexpected_audio');
    assert.equal(storyVideoProblem({ ...good, streams: [] }, 15), 'video_stream_count');
    assert.equal(storyVideoProblem({ ...good, format: { duration: '14.0' } }, 15), 'duration');
    assert.equal(storyVideoProblem({ ...good, format: {} }, 15), 'duration');
    assert.equal(storyVideoProblem(null, 15), 'video_stream_count');
});
