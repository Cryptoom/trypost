import assert from 'node:assert/strict';
import test from 'node:test';
import { amplitude, buildMotion, cameraSegments, coverageExcess, EFFECT_DELAY, EFFECTS, effectExtras, effectUnit, worstCoverageExcess } from '../lib/motion.mjs';

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
    assert.deepEqual(snap[2].to, { scale: 1.04, rotation: 0, x: 20, y: 0 });
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
        assert.ok(focus.pull.at + focus.pull.duration <= duration);
        const sparkle = effectExtras('sparkle', duration);
        assert.equal(sparkle.motes.length, 22);
        for (const mote of sparkle.motes) {
            assert.ok(mote.start + mote.riseTime + mote.fadeTime <= duration + EPSILON, `mote ends after the clip at ${duration}`);
            assert.ok(mote.start >= EFFECT_DELAY - EPSILON, 'a mote starts before the effect delay');
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

// ---- PATCH:story-motion-timing: effects start EFFECT_DELAY late and use the whole clip -----------------------

/** Every moment at which an effect extra starts or ends, for the checks below. */
const extraTimes = (effect, duration) => {
    const extras = effectExtras(effect, duration);
    const starts = [];
    const ends = [];
    for (const key of ['pull', 'vignette', 'leak']) {
        if (extras[key]) {
            starts.push(extras[key].at);
            ends.push(extras[key].at + extras[key].duration);
        }
    }
    for (const mote of extras.motes ?? []) {
        starts.push(mote.start);
        ends.push(mote.start + mote.riseTime + mote.fadeTime);
    }

    return { starts, ends };
};

test('motion timing: the delay is one named second', () => {
    assert.equal(EFFECT_DELAY, 1.0);
});

test('motion timing: no extra starts before the delay and none ends after the clip, for every whole clip length', () => {
    for (const effect of ['focus', 'snap', 'sparkle']) {
        for (let duration = 3; duration <= 20; duration += 1) {
            const { starts, ends } = extraTimes(effect, duration);
            assert.ok(starts.length > 0);
            assert.ok(Math.min(...starts) >= EFFECT_DELAY - EPSILON, `${effect} ${duration}s starts at ${Math.min(...starts)}`);
            assert.ok(Math.max(...ends) <= duration + EPSILON, `${effect} ${duration}s ends at ${Math.max(...ends)}`);
        }
    }
});

test('motion timing: the 8 second clip is the old timing plus one second', () => {
    const at = (value) => Math.round(value * 1e6) / 1e6;
    assert.equal(effectUnit(8), 1);

    const focus = effectExtras('focus', 8);
    assert.deepEqual({ ...focus.pull, at: at(focus.pull.at) }, { at: 1.0, duration: 0.9 * Math.min(1, 8 / 3), blur: 16, brightness: 0.7 });
    assert.deepEqual(focus.vignette, { at: 1.4, duration: 1.2, opacity: 1 });
    assert.deepEqual(effectExtras('snap', 8).leak, { at: 1.5, duration: 2.6, travel: 1300, fade: 0.6 });
    assert.deepEqual(effectExtras('sparkle', 8).vignette, { at: 1.2, duration: 1.0, opacity: 0.8 });

    // the old motes started at rnd * 3 and rose for 2.4 s and faded for 1.6 s, now one second later
    for (const mote of effectExtras('sparkle', 8).motes) {
        assert.ok(mote.start >= 1.0 && mote.start < 4.0);
        assert.equal(mote.riseTime, 2.4);
        assert.equal(mote.fadeTime, 1.6);
    }
});

test('motion timing: a 15 second clip stretches the effects in proportion instead of ending them in the first seconds', () => {
    const unit = effectUnit(15);
    assert.equal(unit, 2);

    assert.deepEqual(effectExtras('snap', 15).leak, { at: 2.0, duration: 5.2, travel: 1300, fade: 1.2 });
    assert.deepEqual(effectExtras('focus', 15).vignette, { at: 1.8, duration: 2.4, opacity: 1 });
    assert.deepEqual(effectExtras('sparkle', 15).vignette, { at: 1.4, duration: 2.0, opacity: 0.8 });

    const { starts, ends } = extraTimes('sparkle', 15);
    assert.ok(Math.max(...starts) > 5, 'mote starts are spread over the clip');
    assert.ok(Math.max(...ends) > 13 && Math.max(...ends) <= 15 + EPSILON, 'the motes are still in the air near the end');
    // the quick focus pull keeps its length
    assert.equal(effectExtras('focus', 15).pull.duration, 0.9);
});

test('motion timing: the snap punch starts at the delay, after a resting pose without a jump', () => {
    const segments = cameraSegments('snap', true, 15);
    assert.equal(segments.length, 3);
    assert.equal(segments[0].at, 0);
    assert.equal(segments[0].duration, EFFECT_DELAY);
    assert.deepEqual(segments[0].from, segments[0].to, 'the wait is a still pose');
    assert.deepEqual(segments[0].to, segments[1].from, 'no jump into the punch');
    assert.equal(segments[1].at, EFFECT_DELAY);
    assert.equal(segments[1].ease, 'expo.out');
    assert.equal(segments[1].duration, 0.9);
    assert.equal(segments[2].at, EFFECT_DELAY + 0.9);
    assert.ok(Math.abs(segments[2].at + segments[2].duration - 15) < EPSILON);
});

test('motion timing: the camera of drift, focus and sparkle still runs over the whole clip and the calm mode is untouched', () => {
    for (const effect of ['drift', 'focus', 'sparkle']) {
        const [segment, ...more] = cameraSegments(effect, true, 15);
        assert.equal(more.length, 0);
        assert.equal(segment.at, 0);
        assert.equal(segment.duration, 15);
    }
    for (const effect of EFFECTS) {
        const calm = cameraSegments(effect, false, 15);
        assert.equal(calm.length, 1);
        assert.deepEqual(calm[0], { at: 0, duration: 15, ease: 'none', from: { scale: 1.02, rotation: 0, x: 0, y: 0 }, to: { scale: 1.0, rotation: 0, x: 0, y: 0 } });
    }
});
