// When each text element comes and goes, in seconds from the start of the clip. All times scale with
// the clip length and never run past it. The composition only replays these numbers.

export const MIN_SWAP_DURATION = 6;
export const MIN_ENDING_DURATION = 4;

const WORD_STEP_MAX = 0.15;
const FADE_OUT = 0.45;

/**
 * @param {object} input
 * @param {number} input.duration clip length in seconds
 * @param {Array<{words: number, hasHeadline: boolean, hasSubline: boolean}>} input.blocks one or two language blocks
 * @param {boolean} input.hasBadge
 * @param {boolean} input.hasEnding
 */
export const textTimeline = ({ duration, blocks, hasBadge, hasEnding }) => {
    const textEnd = hasEnding ? duration - 1.6 : duration;
    const first = 0.5;
    const count = blocks.length;
    const swapAt = count === 2 ? first + (textEnd - first) / 2 : null;
    const clampDuration = (at, wanted) => Math.max(0.05, Math.min(wanted, duration - at));

    const timed = blocks.map((block, index) => {
        const start = index === 0 ? first : swapAt + FADE_OUT;
        const end = index === 0 && count === 2 ? swapAt : textEnd;
        const each = Math.min(WORD_STEP_MAX, ((end - start) * 0.35) / Math.max(block.words, 1));
        const lastWordAt = start + Math.max(block.words - 1, 0) * each;
        const subAt = Math.min(block.hasHeadline ? start + 1.1 : start, duration - 0.65);
        const out = index === 0 && count === 2 ? swapAt : hasEnding ? textEnd : null;

        return {
            headline: block.hasHeadline ? { at: start, each, duration: clampDuration(lastWordAt, 0.7), end: lastWordAt + clampDuration(lastWordAt, 0.7) } : null,
            subline: block.hasSubline ? { at: subAt, duration: clampDuration(subAt, 0.6) } : null,
            out: out === null ? null : { at: out, duration: clampDuration(out, FADE_OUT) },
        };
    });

    const badgeAt = Math.min(1.4, duration - 0.65);

    return {
        blocks: timed,
        scrimTop: { at: 0.2, duration: 0.8, out: hasEnding ? { at: textEnd + 0.1, duration: 0.6 } : null },
        badge: hasBadge ? { at: badgeAt, duration: clampDuration(badgeAt, 0.55), out: hasEnding ? { at: textEnd, duration: 0.4 } : null } : null,
        ending: hasEnding ? { scrimAt: duration - 1.5, scrimDuration: 0.7, at: duration - 1.3, duration: 0.8 } : null,
    };
};

/** Every start and end time of a timeline, for checks against the clip length. */
export const allSpans = (timeline) => {
    const spans = [];
    const add = (at, length) => spans.push([at, at + length]);

    for (const block of timeline.blocks) {
        if (block.headline) {
            spans.push([block.headline.at, block.headline.end]);
        }
        if (block.subline) {
            add(block.subline.at, block.subline.duration);
        }
        if (block.out) {
            add(block.out.at, block.out.duration);
        }
    }
    add(timeline.scrimTop.at, timeline.scrimTop.duration);
    if (timeline.scrimTop.out) {
        add(timeline.scrimTop.out.at, timeline.scrimTop.out.duration);
    }
    if (timeline.badge) {
        add(timeline.badge.at, timeline.badge.duration);
        if (timeline.badge.out) {
            add(timeline.badge.out.at, timeline.badge.out.duration);
        }
    }
    if (timeline.ending) {
        add(timeline.ending.scrimAt, timeline.ending.scrimDuration);
        add(timeline.ending.at, timeline.ending.duration);
    }

    return spans;
};
