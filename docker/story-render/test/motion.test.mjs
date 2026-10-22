import assert from 'node:assert/strict';
import test from 'node:test';
import { amplitude, buildMotion, cameraSegments, coverageExcess, EFFECTS, effectExtras, worstCoverageExcess } from '../lib/motion.mjs';

const EPSILON = 1e-6;
const MODES = [['strong', true], ['calm', false]];

// In every frame the rotated and shifted photo must cover the whole 1080x1920 frame (no gap at any edge).
for (const effect of EFFECTS) {
    for (const [mode, strong] of MODES) {
        test(`motion table: coverage ${effect} ${mode}`, () => {
            for (const duration of [3, 8, 15, 20]) {
                const segments = cameraSegments(effect, strong, duration);
                // start, middle and end of the clip, plus a dense sweep over every segment
                const first = segments[0];
                const last = segments[segments.length - 1];
                assert.ok(coverageExcess(first.from) <= EPSILON, `start of ${effect} ${mode} leaves a gap`);
                assert.ok(coverageExcess(last.to) <= EPSILON, `end of ${effect} ${mode} leaves a gap`);
                const middle = segments.find((segment) => segment.at + segment.duration >= duration / 2) ?? last;
                const half = (key) => (middle.from[key] + middle.to[key]) / 2;
                assert.ok(coverageExcess({ scale: half('scale'), rotation: half('rotation'), x: half('x'), y: half('y') }) <= EPSILON, `middle of ${effect} ${mode} leaves a gap`);
                assert.ok(worstCoverageExcess(segments) <= EPSILON, `${effect} ${mode} leaves a gap somewhere`);
            }
        });
    }
}

test('motion table: the coverage check really sees a gap (negative control)', () => {
    assert.ok(coverageExcess({ scale: 1.0, rotation: 0, x: 30, y: 0 }) > 29);
    assert.ok(coverageExcess({ scale: 1.0, rotation: 0.6, x: 0, y: 0 }) > 5);
    assert.ok(coverageExcess({ scale: 1.2, rotation: 0, x: 0, y: 0 }) < 0);
    assert.ok(worstCoverageExcess([{ from: { scale: 1.0, rotation: -0.6, x: 0, y: 0 }, to: { scale: 1.13, rotation: 0.6, x: -30, y: 0 } }]) > 5, 'focus without its head start of scale would leave a gap');
});

test('motion table: calm values stay under scale 1.02, with no tilt and no drift, and end at the photo itself', () => {
    for (const effect of EFFECTS) {
        const segments = cameraSegments(effect, false, 8);
        const peak = amplitude(segments);
        assert.ok(peak.scale <= 1.02 + EPSILON, `${effect} scale ${peak.scale}`);
        assert.equal(peak.tilt, 0, `${effect} tilt`);
        assert.equal(peak.driftX, 0, `${effect} drift x`);
        assert.equal(peak.driftY, 0, `${effect} drift y`);
        assert.deepEqual(segments[segments.length - 1].to, { scale: 1.0, rotation: 0, x: 0, y: 0 }, `${effect} last frame must equal the photo`);
    }
});

test('motion table: strong values are the agreed ones', () => {
    const peak = (effect) => amplitude(cameraSegments(effect, true, 8));
    assert.deepEqual(peak('drift'), { scale: 1.08, tilt: 0, driftX: 28, driftY: 12 });
    assert.deepEqual(peak('focus'), { scale: 1.13, tilt: 0.6, driftX: 30, driftY: 0 });
    assert.deepEqual(peak('snap'), { scale: 1.2, tilt: 0, driftX: 20, driftY: 30 });
    assert.deepEqual(peak('sparkle'), { scale: 1.11, tilt: 0, driftX: 0, driftY: 20 });
    // snap settles at 1.04 with at most 20 px of shift, focus starts at 1.03
    const snap = cameraSegments('snap', true, 8);
    assert.deepEqual(snap[1].to, { scale: 1.04, rotation: 0, x: 20, y: 0 });
    assert.equal(cameraSegments('focus', true, 8)[0].from.scale, 1.03);
});

test('motion table: segments are contiguous, start at zero and end exactly with the clip', () => {
    for (const effect of EFFECTS) {
        for (const [, strong] of MODES) {
            for (const duration of [3, 8, 20]) {
                const segments = cameraSegments(effect, strong, duration);
                assert.equal(segments[0].at, 0);
                for (let i = 1; i < segments.length; i += 1) {
                    assert.ok(Math.abs(segments[i].at - (segments[i - 1].at + segments[i - 1].duration)) < EPSILON);
                    assert.deepEqual(segments[i].from, segments[i - 1].to, 'segments must join without a jump');
                }
                const end = segments.at(-1);
                assert.ok(Math.abs(end.at + end.duration - duration) < EPSILON);
            }
        }
    }
});

test('motion table: an unknown effect is refused', () => {
    assert.throws(() => cameraSegments('wobble', true, 8), TypeError);
    assert.throws(() => cameraSegments('__proto__', true, 8), TypeError);
});

test('motion extras: focus pull, light leak and light motes are deterministic and inside the clip', () => {
    for (const duration of [3, 8, 15]) {
        const focus = effectExtras('focus', duration);
        assert.ok(focus.pull.duration <= duration && focus.pull.blur === 16 && focus.pull.brightness === 0.7);
        const snap = effectExtras('snap', duration);
        assert.ok(snap.leak.at + snap.leak.duration <= duration);
        const sparkle = effectExtras('sparkle', duration);
        assert.equal(sparkle.motes.length, 22);
        for (const mote of sparkle.motes) {
            assert.ok(mote.start + mote.riseTime + mote.fadeTime <= duration + EPSILON, `mote ends after the clip at ${duration}`);
            assert.ok(mote.left >= 80 && mote.left <= 1000 && mote.top >= 700 && mote.top <= 1700);
        }
        assert.deepEqual(effectExtras('sparkle', duration), sparkle, 'same numbers every run');
    }
    assert.deepEqual(effectExtras('drift', 8), { pull: null, vignette: null, leak: null, motes: null });
});

test('motion table: buildMotion carries effect, mode and segments', () => {
    const motion = buildMotion('focus', true, 8);
    assert.equal(motion.effect, 'focus');
    assert.equal(motion.strong, true);
    assert.equal(motion.segments.length, 1);
    assert.ok(motion.pull);
});

test('motion coverage check: shift and tilt are judged the same way in both rotation directions', () => {
    for (const state of [
        { scale: 1.01, rotation: 0.5, x: 6, y: 0 },
        { scale: 1.02, rotation: -0.6, x: -20, y: 10 },
        { scale: 1.0, rotation: 1, x: 30, y: -40 },
    ]) {
        assert.equal(coverageExcess(state), coverageExcess({ ...state, rotation: -state.rotation }), JSON.stringify(state));
    }
    // A state that only leaves a gap if the tilt turns one way must still be reported.
    assert.ok(coverageExcess({ scale: 1.012, rotation: 0.6, x: 12, y: 0 }) > 0);
    assert.ok(coverageExcess({ scale: 1.012, rotation: -0.6, x: 12, y: 0 }) > 0);
});
