import assert from 'node:assert/strict';
import test from 'node:test';
import { storyVideoProblem } from '../lib/probe.mjs';
import { inspectImage, LIMITS, ValidationError } from '../lib/validate.mjs';
import { fakeJpeg, fakePng } from '../testkit/helpers.mjs';

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

test('image: a PNG needs all eight signature bytes, a correct start and a wrong rest is no PNG', () => {
    const good = fakePng(100, 100);
    assert.equal(inspectImage(good).type, 'png');
    for (let index = 4; index < 8; index += 1) {
        const broken = Buffer.from(good);
        broken[index] = broken[index] ^ 0xff;
        rejects(() => inspectImage(broken), 'unsupported_image_type', 415);
    }
    rejects(() => inspectImage(Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47]), good.subarray(4, 7)])), 'unsupported_image_type', 415);
});

test('image: a logo is held to its own, smaller side limit', () => {
    assert.equal(inspectImage(fakePng(2000, 2000), { maxSide: LIMITS.maxLogoSide }).width, 2000);
    rejects(() => inspectImage(fakePng(2001, 100), { maxSide: LIMITS.maxLogoSide }), 'image_dimensions_too_large', 413);
    rejects(() => inspectImage(fakeJpeg(100, 2001), { maxSide: LIMITS.maxLogoSide }), 'image_dimensions_too_large', 413);
});

test('image: rejects truncated headers and oversize dimensions', () => {
    rejects(() => inspectImage(Buffer.from([0xff, 0xd8, 0xff, 0xe0, 0x00, 0x10])), 'invalid_image');
    rejects(() => inspectImage(fakePng(4097, 100)), 'image_dimensions_too_large', 413);
    rejects(() => inspectImage(fakeJpeg(100, 4097)), 'image_dimensions_too_large', 413);
    assert.equal(inspectImage(fakeJpeg(4096, 4096)).width, 4096);
    rejects(() => inspectImage(fakePng(0, 10)), 'invalid_image');
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
