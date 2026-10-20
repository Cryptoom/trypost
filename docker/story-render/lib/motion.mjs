// Camera motion for the four image effects, as plain numbers. The composition (template/story.js) only
// replays what this module returns, so the amplitude rule and the "photo always covers the frame" rule
// can be tested in Node without a browser.

export const EFFECTS = Object.freeze(['drift', 'focus', 'snap', 'sparkle']);

export const FRAME = Object.freeze({ width: 1080, height: 1920 });

/**
 * Strong values are used only when the logo layer AND the AI notice layer are both on (the brand bundle):
 * then the final frame may differ from the photo, because the layers carry logo and notice. Without both,
 * the photo may carry baked-in logos or notices, so every effect stays calm: scale at most 1.02 above the
 * final frame, no tilt, no drift, and the last frame equals the photo (scale 1.00).
 *
 * Every segment moves all its properties with ONE ease, so progress p in [0, 1] is shared by scale,
 * rotation, x and y. That is what the coverage check below relies on.
 */
const STRONG = Object.freeze({
    drift: [{ at: 0, whole: true, ease: 'sine.inOut', from: { scale: 1.0, rotation: 0, x: 0, y: 0 }, to: { scale: 1.08, rotation: 0, x: -28, y: -12 } }],
    focus: [{ at: 0, whole: true, ease: 'sine.inOut', from: { scale: 1.03, rotation: -0.6, x: 0, y: 0 }, to: { scale: 1.13, rotation: 0.6, x: -30, y: 0 } }],
    snap: [
        { at: 0, length: 0.9, ease: 'expo.out', from: { scale: 1.2, rotation: 0, x: 0, y: 30 }, to: { scale: 1.06, rotation: 0, x: 0, y: 0 } },
        { at: 0.9, rest: true, ease: 'sine.inOut', from: { scale: 1.06, rotation: 0, x: 0, y: 0 }, to: { scale: 1.04, rotation: 0, x: 20, y: 0 } },
    ],
    sparkle: [{ at: 0, whole: true, ease: 'sine.inOut', from: { scale: 1.0, rotation: 0, x: 0, y: 0 }, to: { scale: 1.11, rotation: 0, x: 0, y: -20 } }],
});

const CALM = Object.freeze([{ at: 0, whole: true, ease: 'none', from: { scale: 1.02, rotation: 0, x: 0, y: 0 }, to: { scale: 1.0, rotation: 0, x: 0, y: 0 } }]);

/**
 * @param {string} effect
 * @param {boolean} strong
 * @param {number} duration seconds
 * @returns {Array<{at: number, duration: number, ease: string, from: object, to: object}>}
 */
export const cameraSegments = (effect, strong, duration) => {
    if (!EFFECTS.includes(effect)) {
        throw new TypeError('unknown effect');
    }
    const table = strong ? STRONG[effect] : CALM;

    return table.map((segment) => ({
        at: segment.at,
        duration: segment.whole ? duration : segment.rest ? duration - segment.at : segment.length,
        ease: segment.ease,
        from: { ...segment.from },
        to: { ...segment.to },
    }));
};

/**
 * Largest amount (in pixels) by which a corner of the 1080x1920 frame lies outside the transformed photo.
 * Zero or less means the photo covers the whole frame. The photo is a 1080x1920 box scaled and rotated about
 * its centre, then shifted by (x, y); rotation is checked in both directions so the sign convention of the
 * renderer cannot matter.
 */
export const coverageExcess = ({ scale, rotation, x, y }) => {
    const halfW = FRAME.width / 2;
    const halfH = FRAME.height / 2;
    let worst = -Infinity;

    for (const sign of [1, -1]) {
        const angle = (sign * rotation * Math.PI) / 180;
        const cos = Math.cos(angle);
        const sin = Math.sin(angle);

        for (const cx of [-halfW, halfW]) {
            for (const cy of [-halfH, halfH]) {
                const dx = cx - x;
                const dy = cy - y;
                const lx = dx * cos + dy * sin;
                const ly = -dx * sin + dy * cos;
                worst = Math.max(worst, Math.abs(lx) - (scale * halfW), Math.abs(ly) - (scale * halfH));
            }
        }
    }

    return worst;
};

/**
 * Samples every segment densely and returns the worst coverage excess over all of them.
 */
export const worstCoverageExcess = (segments, samples = 100) => {
    let worst = -Infinity;
    for (const segment of segments) {
        for (let i = 0; i <= samples; i += 1) {
            const p = i / samples;
            const at = {};
            for (const key of ['scale', 'rotation', 'x', 'y']) {
                at[key] = segment.from[key] + (segment.to[key] - segment.from[key]) * p;
            }
            worst = Math.max(worst, coverageExcess(at));
        }
    }

    return worst;
};

/**
 * Largest absolute value of each camera property over all segments (start and end values).
 */
export const amplitude = (segments) => {
    const peak = { scale: 1, tilt: 0, driftX: 0, driftY: 0 };
    for (const segment of segments) {
        for (const state of [segment.from, segment.to]) {
            peak.scale = Math.max(peak.scale, state.scale);
            peak.tilt = Math.max(peak.tilt, Math.abs(state.rotation));
            peak.driftX = Math.max(peak.driftX, Math.abs(state.x));
            peak.driftY = Math.max(peak.driftY, Math.abs(state.y));
        }
    }

    return peak;
};

// Small deterministic generator (same numbers on every run, so a render is reproducible).
const seeded = (start) => {
    let seed = start;

    return () => {
        seed = (seed * 9301 + 49297) % 233280;

        return seed / 233280;
    };
};

/**
 * Everything an effect adds on top of the camera move, scaled to the clip length.
 * Times are seconds from the start of the clip and never run past `duration`.
 */
export const effectExtras = (effect, duration) => {
    const unit = Math.min(1, duration / 8);
    const extras = { pull: null, vignette: null, leak: null, motes: null };

    if (effect === 'focus') {
        // Focus pull: opens soft and dim, snaps into focus.
        extras.pull = { duration: 0.9 * Math.min(1, duration / 3), blur: 16, brightness: 0.7 };
        extras.vignette = { at: 0.4 * unit, duration: 1.2 * unit, opacity: 1 };
    } else if (effect === 'snap') {
        // One warm light leak passing from left to right.
        extras.leak = { at: 0.5 * unit, duration: 2.6 * unit, travel: 1300, fade: 0.6 * unit };
    } else if (effect === 'sparkle') {
        extras.vignette = { at: 0.2 * unit, duration: 1.0 * unit, opacity: 0.8 };
        const rnd = seeded(7);
        extras.motes = Array.from({ length: 22 }, () => {
            const size = 18 + Math.round(rnd() * 46);
            const left = Math.round(80 + rnd() * 920);
            const top = Math.round(700 + rnd() * 1000);
            const start = rnd() * 3 * unit;
            const rise = 260 + rnd() * 260;
            const peak = 0.55 + rnd() * 0.35;

            return { size, left, top, start, rise: Math.round(rise), peak: Math.round(peak * 100) / 100, riseTime: 2.4 * unit, fadeTime: 1.6 * unit };
        });
    }

    return extras;
};

/**
 * The whole motion description for one job.
 */
export const buildMotion = (effect, strong, duration) => ({
    effect,
    strong,
    segments: cameraSegments(effect, strong, duration),
    ...effectExtras(effect, duration),
});
