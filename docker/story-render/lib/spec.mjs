import { textOn } from './colors.mjs';
import { FONTS, FONT_KEYS } from './fonts.mjs';
import { buildMotion, EFFECTS } from './motion.mjs';
import { headlineTokens, normalizeText, sanitizeText, sublineParts, textLength, withoutAccentMarks } from './text.mjs';
import { MIN_ENDING_DURATION, MIN_SWAP_DURATION, textTimeline } from './timeline.mjs';
import { LIMITS, ValidationError } from './validate.mjs';

const TOP_LEVEL = Object.freeze(['duration', 'effect', 'font', 'accent', 'logoLayer', 'aiNotice', 'headline', 'subline', 'badge', 'headlineAlt', 'sublineAlt', 'ending']);
const REQUIRED = Object.freeze(['duration', 'effect', 'font', 'accent', 'logoLayer']);
const ENDING_KEYS = Object.freeze(['name', 'line']);

/** Shown when the font cannot draw the entire requested AI notice. */
export const AI_NOTICE_FALLBACK = 'AI-generated image';

/** Maximum lengths in characters. Accent marks (`*`) do not count in the two headlines. */
export const TEXT_LIMITS = Object.freeze({ headline: 80, subline: 60, badge: 12, headlineAlt: 80, sublineAlt: 60, aiNotice: 40, 'ending.name': 40, 'ending.line': 60 });

const isPlainObject = (value) => value !== null && typeof value === 'object' && !Array.isArray(value);

const bad = (code, field) => new ValidationError(code, 400, field);

// Own property names only: a "__proto__" key from JSON.parse is an own key and fails the allowlist like any other.
const assertKeys = (object, allowed, required = []) => {
    for (const key of Object.getOwnPropertyNames(object)) {
        if (!allowed.includes(key)) {
            throw bad('spec_unknown_key');
        }
    }
    for (const key of required) {
        if (!Object.hasOwn(object, key)) {
            throw bad('spec_missing_key', key);
        }
    }
};

const optionalText = (object, key, limit, field = key, countable = (text) => text) => {
    const value = Object.hasOwn(object, key) ? object[key] : null;
    if (value === null) {
        return null;
    }
    if (typeof value !== 'string') {
        throw bad('spec_invalid_type', field);
    }
    if (textLength(countable(value)) > limit) {
        throw bad('spec_text_too_long', field);
    }

    return value;
};

/**
 * Parses and strictly validates the `spec` JSON text. Unknown keys, wrong types and values outside the
 * limits are rejected; nothing is guessed or defaulted except the optional text fields, which may be
 * absent, null or empty.
 */
export const parseSpec = (raw) => {
    if (typeof raw !== 'string' || Buffer.byteLength(raw, 'utf8') > LIMITS.maxSpecBytes) {
        throw bad('spec_too_large');
    }
    let spec;
    try {
        spec = JSON.parse(raw);
    } catch {
        throw bad('spec_invalid_json');
    }
    if (!isPlainObject(spec)) {
        throw bad('spec_invalid_json');
    }
    assertKeys(spec, TOP_LEVEL, REQUIRED);

    if (!Number.isInteger(spec.duration) || spec.duration < LIMITS.minDuration || spec.duration > LIMITS.maxDuration) {
        throw bad('spec_invalid_duration', 'duration');
    }
    if (typeof spec.effect !== 'string' || !EFFECTS.includes(spec.effect)) {
        throw bad('spec_invalid_effect', 'effect');
    }
    if (typeof spec.font !== 'string' || !FONT_KEYS.includes(spec.font)) {
        throw bad('spec_invalid_font', 'font');
    }
    if (typeof spec.accent !== 'string' || !/^#[0-9A-F]{6}$/.test(spec.accent)) {
        throw bad('spec_invalid_accent', 'accent');
    }
    if (typeof spec.logoLayer !== 'boolean') {
        throw bad('spec_invalid_type', 'logoLayer');
    }

    let ending = null;
    if (Object.hasOwn(spec, 'ending') && spec.ending !== null) {
        if (!isPlainObject(spec.ending)) {
            throw bad('spec_invalid_type', 'ending');
        }
        assertKeys(spec.ending, ENDING_KEYS);
        ending = {
            name: optionalText(spec.ending, 'name', TEXT_LIMITS['ending.name'], 'ending.name'),
            line: optionalText(spec.ending, 'line', TEXT_LIMITS['ending.line'], 'ending.line'),
        };
    }

    return {
        duration: spec.duration,
        effect: spec.effect,
        font: spec.font,
        accent: spec.accent,
        logoLayer: spec.logoLayer,
        aiNotice: optionalText(spec, 'aiNotice', TEXT_LIMITS.aiNotice),
        headline: optionalText(spec, 'headline', TEXT_LIMITS.headline, 'headline', withoutAccentMarks),
        subline: optionalText(spec, 'subline', TEXT_LIMITS.subline),
        badge: optionalText(spec, 'badge', TEXT_LIMITS.badge),
        headlineAlt: optionalText(spec, 'headlineAlt', TEXT_LIMITS.headlineAlt, 'headlineAlt', withoutAccentMarks),
        sublineAlt: optionalText(spec, 'sublineAlt', TEXT_LIMITS.sublineAlt),
        ending,
    };
};

/**
 * Display size of the logo: 64 px high, at most 640 px wide (the white card adds 20 px each side and 10 px
 * top and bottom, so the card is at most 680 px wide and 84 px high).
 */
export const LOGO_HEIGHT = 64;
export const LOGO_MAX_WIDTH = 640;

export const logoSize = ({ width, height }) => {
    let h = LOGO_HEIGHT;
    let w = Math.round((LOGO_HEIGHT * width) / height);
    if (w > LOGO_MAX_WIDTH) {
        w = LOGO_MAX_WIDTH;
        h = Math.max(1, Math.round((LOGO_MAX_WIDTH * height) / width));
    }

    return { w: Math.max(1, w), h };
};

const block = (headlineText, sublineText, isCovered) => {
    const headline = headlineText === null ? [] : headlineTokens(headlineText, isCovered);
    const subline = sublineText === null ? [] : sublineParts(sublineText, isCovered);

    return headline.length === 0 && subline.length === 0 ? null : { headline: headline.length > 0 ? headline : null, subline: subline.length > 0 ? subline : null };
};

/**
 * Everything the composition needs, derived from a validated spec: sanitized text, motion, timing.
 *
 * @param {ReturnType<typeof parseSpec>} spec
 * @param {{logoInfo: {ext: string, width: number, height: number} | null, coverages: Record<string, (codePoint: number) => boolean>}} context
 */
export const buildJob = (spec, { logoInfo, coverages }) => {
    const isCovered = coverages[spec.font];
    if (spec.logoLayer && logoInfo === null) {
        throw bad('logo_required');
    }
    if (!spec.logoLayer && logoInfo !== null) {
        throw bad('logo_unexpected');
    }

    // A mandatory label must remain complete. Normalize harmless layout differences and canonical
    // Unicode composition first; losing any remaining character selects the controlled English notice.
    const normalizedNotice = spec.aiNotice === null ? null : normalizeText(spec.aiNotice);
    const drawnNotice = normalizedNotice === null ? null : sanitizeText(normalizedNotice, isCovered);
    const aiNoticeFallback = drawnNotice !== null && (!/\p{L}/u.test(drawnNotice) || drawnNotice !== normalizedNotice);
    const aiNotice = aiNoticeFallback ? AI_NOTICE_FALLBACK : drawnNotice;
    const strong = spec.logoLayer && aiNotice !== null;

    const blocks = [block(spec.headline, spec.subline, isCovered)];
    // The second language replaces the first one softly; on a clip too short for that it is left out.
    if (spec.duration >= MIN_SWAP_DURATION) {
        blocks.push(block(spec.headlineAlt, spec.sublineAlt, isCovered));
    }
    const shown = blocks.filter((entry) => entry !== null);

    const badge = spec.badge === null ? null : sanitizeText(spec.badge, isCovered) || null;

    let ending = null;
    if (spec.ending !== null && spec.duration >= MIN_ENDING_DURATION) {
        const name = spec.ending.name === null ? '' : sanitizeText(spec.ending.name, isCovered);
        const line = spec.ending.line === null ? '' : sanitizeText(spec.ending.line, isCovered);
        ending = name === '' && line === '' ? null : { name: name || null, line: line || null };
    }

    const hasText = shown.length > 0;

    return {
        duration: spec.duration,
        effect: spec.effect,
        font: spec.font,
        fontMeta: FONTS[spec.font],
        accent: spec.accent,
        accentText: textOn(spec.accent),
        logo: spec.logoLayer ? { ext: logoInfo.ext, ...logoSize(logoInfo) } : null,
        aiNotice,
        aiNoticeFallback,
        strong,
        motion: buildMotion(spec.effect, strong, spec.duration),
        blocks: shown,
        badge,
        ending,
        timeline: textTimeline({
            duration: spec.duration,
            blocks: shown.map((entry) => ({ words: entry.headline?.length ?? 0, hasHeadline: entry.headline !== null, hasSubline: entry.subline !== null })),
            hasBadge: badge !== null,
            hasEnding: ending !== null,
        }),
        hasText,
    };
};
