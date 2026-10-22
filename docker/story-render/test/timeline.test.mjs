import assert from 'node:assert/strict';
import test from 'node:test';
import { allSpans, MIN_ENDING_DURATION, MIN_SWAP_DURATION, textTimeline } from '../lib/timeline.mjs';

const block = (words = 5) => ({ words, hasHeadline: true, hasSubline: true });
const EPSILON = 1e-9;

test('timeline: every start and end time stays inside the clip for every length and element mix', () => {
    for (let duration = 3; duration <= 20; duration += 1) {
        for (const blocks of [[], [block()], [block(1)], [block(12), block(12)], [{ words: 0, hasHeadline: false, hasSubline: true }]]) {
            for (const hasBadge of [false, true]) {
                for (const hasEnding of duration >= MIN_ENDING_DURATION ? [false, true] : [false]) {
                    const timeline = textTimeline({ duration, blocks: duration >= MIN_SWAP_DURATION ? blocks : blocks.slice(0, 1), hasBadge, hasEnding });
                    for (const [from, to] of allSpans(timeline)) {
                        assert.ok(from >= -EPSILON, `starts before zero at ${duration}s`);
                        assert.ok(to <= duration + EPSILON, `runs past the clip at ${duration}s (${to})`);
                        assert.ok(to >= from, 'negative span');
                    }
                }
            }
        }
    }
});

test('timeline: times scale with the clip, the 8 second demo timing is kept', () => {
    const timeline = textTimeline({ duration: 8, blocks: [block(4), block(5)], hasBadge: true, hasEnding: true });
    assert.equal(timeline.blocks[0].headline.at, 0.5);
    assert.ok(timeline.blocks[0].subline.at > timeline.blocks[0].headline.at);
    // the second language comes in after the first has left, both before the ending
    assert.ok(timeline.blocks[0].out.at < timeline.blocks[1].headline.at);
    assert.ok(timeline.blocks[1].out.at <= 8 - 1.5);
    assert.ok(timeline.ending.at >= 8 - 1.5 && timeline.ending.at + timeline.ending.duration <= 8);
    const longer = textTimeline({ duration: 15, blocks: [block(4), block(5)], hasBadge: true, hasEnding: true });
    assert.ok(longer.blocks[1].headline.at > timeline.blocks[1].headline.at, 'the swap moves later with a longer clip');
    assert.ok(Math.abs(longer.ending.at - (15 - 1.3)) < EPSILON, 'the ending always sits in the last 1.5 seconds');
});

test('timeline: the headline and subline of the ending clip leave before the ending name comes in', () => {
    const timeline = textTimeline({ duration: 10, blocks: [block()], hasBadge: true, hasEnding: true });
    const out = timeline.blocks[0].out;
    assert.ok(out.at + out.duration <= timeline.ending.at + 0.2);
    assert.ok(timeline.badge.out.at <= timeline.ending.at);
    assert.ok(timeline.scrimTop.out.at >= out.at);
});

test('timeline: without an ending nothing leaves, the text stays to the last frame', () => {
    const timeline = textTimeline({ duration: 8, blocks: [block()], hasBadge: true, hasEnding: false });
    assert.equal(timeline.blocks[0].out, null);
    assert.equal(timeline.badge.out, null);
    assert.equal(timeline.scrimTop.out, null);
    assert.equal(timeline.ending, null);
});

test('timeline: the word step shrinks for long headlines so the last word still lands in time', () => {
    const few = textTimeline({ duration: 8, blocks: [block(3)], hasBadge: false, hasEnding: false });
    const many = textTimeline({ duration: 8, blocks: [block(30)], hasBadge: false, hasEnding: false });
    assert.equal(few.blocks[0].headline.each, 0.15);
    assert.ok(many.blocks[0].headline.each < 0.15);
    assert.ok(many.blocks[0].headline.end <= 8);
});

test('timeline: the thresholds are 6 seconds for the language swap and 4 for the ending', () => {
    assert.equal(MIN_SWAP_DURATION, 6);
    assert.equal(MIN_ENDING_DURATION, 4);
});
