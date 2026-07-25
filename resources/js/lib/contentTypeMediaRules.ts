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

export const maxVideoDurationSecFor = (contentType: string): number | undefined => {
    const seconds = cachedRules?.[contentType]?.max_video_duration_sec;

    return typeof seconds === 'number' ? seconds : undefined;
};
