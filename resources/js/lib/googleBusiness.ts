/**
 * Google Business Profile Local Post constants shared by the editor settings
 * panel, the preview, and the publish compliance gate.
 */

/**
 * Topic types whose Local Post requires an `event` object (title + date range).
 * Mirrors PostPlatformMetaRules::GOOGLE_BUSINESS_EVENT_TOPIC_TYPES.
 */
export const GOOGLE_BUSINESS_EVENT_TOPIC_TYPES: string[] = ['EVENT', 'OFFER'];
