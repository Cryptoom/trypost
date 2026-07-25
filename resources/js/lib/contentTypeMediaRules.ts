import type { Page } from '@inertiajs/core';

export type ContentTypeMediaRule = {
    max_video_duration_sec: number | null;
};

type ContentTypeMediaRulesMap = Record<string, ContentTypeMediaRule>;

let cachedRules: ContentTypeMediaRulesMap | null = null;

export const syncContentTypeMediaRules = (page: Page): void => {
    const rules = page.props.contentTypeMediaRules as ContentTypeMediaRulesMap | undefined;

    if (rules) {
        cachedRules = rules;
    }
};

/**
 * Shared Inertia duration for a content type.
 * - `undefined` — cache not synced yet (or unknown type): keep local fallbacks
 * - `null` — explicitly unlimited / dynamic (e.g. TikTok)
 * - `number` — hard cap in seconds from ContentType::maxVideoDurationSec()
 */
export const maxVideoDurationSecFor = (contentType: string): number | null | undefined => {
    if (cachedRules === null) {
        return undefined;
    }

    const rule = cachedRules[contentType];

    if (rule === undefined) {
        return undefined;
    }

    return rule.max_video_duration_sec;
};
