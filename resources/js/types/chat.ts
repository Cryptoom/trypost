import type { PostPlatformStatusValue, PostStatusValue } from '@/types/post';

/**
 * Mirrors `App\Http\Resources\Chat\ChatPostResource`. Every field beyond `id`
 * is optional: `platforms` is `whenLoaded()` on the backend (absent unless
 * the relation was eager loaded), and `DeletePostTool` returns a bare
 * `{ id, deleted }` instead of the full resource shape.
 */
export interface ChatPostPlatform {
    platform: string | null;
    handle: string | null;
    status: PostPlatformStatusValue | string | null;
}

export interface ChatPost {
    id: string;
    content?: string | null;
    /** True when `content` is a shortened preview — `list_posts` entries only. */
    content_truncated?: boolean;
    status?: PostStatusValue | string | null;
    scheduled_at?: string | null;
    published_at?: string | null;
    platforms?: ChatPostPlatform[];
    deleted?: boolean;
}

/** One row of `App\Services\Post\PostMetricsFetcher::forPlatform()`'s array shape. */
export interface ChatPostMetricRow {
    label: string;
    value: number;
    kind?: string;
}

export type ChatPostMetricsValue = ChatPostMetricRow[] | { unsupported: true; reason: string };

/** Mirrors one entry of `App\Http\Resources\Chat\ChatPostMetricsResource`'s `platforms` array. */
export interface ChatPostMetricsPlatform {
    post_platform_id: string;
    platform: string;
    status: string;
    platform_post_id: string | null;
    platform_url: string | null;
    metrics: ChatPostMetricsValue;
}

/** Mirrors `App\Http\Resources\Chat\ChatPostMetricsResource`. */
export interface ChatPostMetrics {
    post_id: string;
    platforms: ChatPostMetricsPlatform[];
}

/**
 * A loose mirror of `ai`'s `ToolUIPart`, scoped to the fields ChatToolPart
 * and its cards actually read. The SDK's real type is parameterized over a
 * ToolSet this app never declares on the TS side — the tool name only exists
 * as the `tool-<name>` string every `WorkspaceTool` subclass agrees on (see
 * `App\Ai\Tools\WorkspaceTool::name()`).
 */
export interface ChatToolInvocation {
    type: string;
    toolCallId: string;
    state:
        | 'input-streaming'
        | 'input-available'
        | 'approval-requested'
        | 'approval-responded'
        | 'output-available'
        | 'output-error'
        | 'output-denied';
    input?: unknown;
    output?: string;
    errorText?: string;
    approval?: {
        id: string;
        approved?: boolean;
        reason?: string;
        isAutomatic?: boolean;
        signature?: string;
    };
}

/**
 * Forwarded by `ChatApprovalCard` through `ChatToolPart` to the page, which
 * turns it into `useConversationChat`'s `submitDecisions({ [id]: decision })`.
 * `toolCallId` carries the *approval* id (`part.approval.id`), not the tool
 * call id — `addToolApprovalResponse` keys on the former, and the two only
 * ever coincide by accident.
 */
export interface ChatApprovalDecision {
    toolCallId: string;
    action: 'approve' | 'reject';
    result?: string;
}

/** Mirrors `App\Http\Resources\Chat\ConversationResource`. */
export interface ChatConversationSummary {
    id: string;
    title: string | null;
    status: string | null;
    updated_at: string | null;
}

/**
 * One entry of a stored assistant message's `tool_calls` array — mirrors
 * `Laravel\Ai\Responses\Data\ToolCall::toArray()`, narrowed to the fields the
 * frontend reads back out when replaying a reopened conversation.
 */
export interface ChatServerToolCall {
    id: string;
    name: string;
    arguments: Record<string, unknown> | null;
}

/**
 * One entry of a stored turn's `parts` — the message's text and tool cards in
 * the order the model produced them. A tool part carries only the call it
 * points at; its arguments live on the matching `tool_calls` entry and its
 * payload in `payloads`, both keyed by the same `id`.
 */
export type ChatServerMessagePart = { type: 'text'; text: string } | { type: 'tool'; id: string; name: string };

/**
 * Mirrors `App\Http\Resources\Chat\ConversationMessageResource`. `payloads`
 * is keyed by tool call id, scoped to this message's own `tool_calls` (see
 * the resource's docblock) — never the whole conversation's payload map.
 *
 * `parts` is null on every row stored before the column existed, which is
 * why `buildInitialMessages` keeps a fallback.
 */
export interface ChatServerMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string | null;
    parts: ChatServerMessagePart[] | null;
    tool_calls: ChatServerToolCall[] | null;
    payloads: Record<string, string>;
}

/** One connected account `start_post_generation` offers for a format. */
export interface ChatPostGenerationAccount {
    id: string;
    label: string;
    /**
     * The handle, without its "@". Present alongside `label` because the label
     * alone cannot identify an account: a workspace connected to Instagram
     * both directly and through a Facebook Page has two accounts that share a
     * display name and a logo, and only the handle tells them apart.
     */
    username: string | null;
    platform: string;
}

/**
 * One entry of the catalog's `formats` list. The same `value` appears once
 * per platform that can post it, so an Instagram format is listed twice when
 * both an `instagram` and an `instagram-facebook` account are connected.
 */
export interface ChatPostGenerationFormat {
    value: string;
    platform: string;
    /**
     * The format's display name, resolved server-side in the conversation's
     * language. The card renders it as-is: `posts.create.steps.format.*` is
     * bound to the app locale on the client, which is not the language the
     * thread is being held in.
     */
    label: string;
    accounts: ChatPostGenerationAccount[];
}

/**
 * Every line `ChatPostGenerationCard` renders, resolved server-side in the
 * conversation's language. Mirrors
 * `App\Services\Ai\PostGenerationCardCopy::forLocale()`; lines that carry
 * `:placeholders` arrive as templates the card fills in.
 */
export interface ChatPostGenerationCopy {
    unavailable: string;
    styles_unavailable: string;
    format_question: string;
    style_question: string;
    topic_question: string;
    topic_placeholder: string;
    topic_too_long: string;
    images_question: string;
    images_none: string;
    account_question: string;
    posting_to: string;
    brand_colors_label: string;
    brand_colors_description: string;
    change: string;
    submit: string;
    sent: string;
    sentence: string;
    sentence_with_brand: string;
    sentence_images_none: string;
    sentence_images_one: string;
    sentence_images_other: string;
    sentence_brand_on: string;
    sentence_brand_off: string;
}

/** One AI content template. `name` and `description` arrive translated. */
export interface ChatPostGenerationStyle {
    key: string;
    name: string;
    description: string;
    preview: string;
    needs_account: boolean;
    supported_formats: string[];
    applies_brand_visuals: boolean;
}

/**
 * Mirrors `App\Ai\Tools\Post\GeneratePostTool`'s payload. The tool returns as
 * soon as the generation is queued, so it names the private channel the
 * finished post is announced on rather than carrying the post.
 *
 * `post` is not part of that payload: `App\Ai\Tools\ToolReplayer` merges it in
 * when a reopened conversation's `creation_id` still resolves to a post, which
 * is the only way a card that missed the broadcast can ever show one.
 */
export interface ChatPostGeneration {
    creation_id: string;
    /**
     * Optional because the card must survive a payload that never named one —
     * an older or hand-written stored result, or a tool return that changed
     * shape. `ChatPostGenerationResult` treats its absence as a failure rather
     * than subscribing to `private-undefined`.
     */
    channel?: string;
    post?: ChatPost | null;
    /**
     * Set by `ToolReplayer` when the generation is over and produced no post —
     * the turn is older than the whole generation window, so the broadcast
     * either fired and was missed or never came. Distinguishes "nothing is
     * coming" from "still running", which a bare missing `post` cannot.
     */
    settled?: boolean;
}

/** Mirrors `App\Ai\Tools\Post\StartPostGenerationTool`'s payload. */
export interface ChatPostGenerationCatalog {
    formats: ChatPostGenerationFormat[];
    styles: ChatPostGenerationStyle[];
    applies_brand_visuals_default: boolean;
    /**
     * The locale every string in this payload was resolved in — the language
     * the user is writing in, which the model reported, or the app locale
     * when it reported none. Carried for the record; the card reads the
     * resolved strings, never the code.
     */
    locale?: string;
    /**
     * Every line the card displays. Optional only so a payload stored before
     * the card stopped translating client-side still renders: the card then
     * falls back to the app locale, which is the old behaviour rather than a
     * blank card.
     */
    copy?: ChatPostGenerationCopy;
    /**
     * The format the model read from the user's own message, already checked
     * against this workspace's catalog. Null when the user named none, named
     * one that does not exist, or named one this workspace cannot post — the
     * card then asks. Unlike `topic` this is recorded as an answered choice
     * rather than a pre-filled question: it comes from a closed list, so a
     * wrong pick is legible in the record and undone with the Change link.
     */
    format?: string | null;
    /**
     * What the model understood the post should be about, echoed back from
     * `start_post_generation`'s own `topic` argument. Empty when the user
     * never said — the card then asks with a blank field rather than a
     * subject nobody chose. The card always asks either way: the user has to
     * see the topic before it is generated from.
     */
    topic?: string | null;
    /**
     * Set by `App\Ai\Tools\ToolReplayer` when the conversation went on to
     * call `generate_post`: the choices this card collects were already sent,
     * so it renders settled. Without it a reopened conversation would re-arm a
     * single-use form, and a second submit would bill another generation.
     */
    spent?: boolean;
}
