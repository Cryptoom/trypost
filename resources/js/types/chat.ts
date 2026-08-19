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
 * Mirrors `App\Http\Resources\Chat\ConversationMessageResource`. `payloads`
 * is keyed by tool call id, scoped to this message's own `tool_calls` (see
 * the resource's docblock) — never the whole conversation's payload map.
 */
export interface ChatServerMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string | null;
    tool_calls: ChatServerToolCall[] | null;
    payloads: Record<string, string>;
}
