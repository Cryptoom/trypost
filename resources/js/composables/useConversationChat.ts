import { useChat } from '@ai-sdk/vue';
import { DefaultChatTransport, isTextUIPart, isToolUIPart, lastAssistantMessageIsCompleteWithApprovalResponses, type UIMessage } from 'ai';
import { trans } from 'laravel-vue-i18n';

import { store } from '@/actions/App/Http/Controllers/App/ChatMessageController';

/**
 * One approval decision for a paused tool call, keyed by its call id when
 * submitted through `submitDecisions`. Mirrors the shape
 * `Laravel\Ai\Approvals\Decision` accepts on the wire — `result` only makes
 * sense alongside a `reject`, matching `Decision::reject(?string $result)`.
 */
export interface ChatDecision {
    action: 'approve' | 'reject';
    result?: string;
}

/**
 * Read the URL-decoded XSRF-TOKEN cookie so it can be echoed back as the
 * X-XSRF-TOKEN header. Without it Laravel's CSRF middleware answers 419.
 */
const readXsrfToken = (): string | null => {
    const match = document.cookie
        .split(';')
        .map((row) => row.trim())
        .find((row) => row.startsWith('XSRF-TOKEN='));

    return match === undefined ? null : decodeURIComponent(match.slice('XSRF-TOKEN='.length));
};

const requestHeaders = (): Record<string, string> => {
    const headers: Record<string, string> = {
        Accept: 'text/event-stream, application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = readXsrfToken();

    if (token !== null) {
        headers['X-XSRF-TOKEN'] = token;
    }

    return headers;
};

/**
 * Extract the plain text of the newest message so the request body carries
 * only the new prompt, never the client's transcript — conversation history
 * lives server-side in WorkspaceConversationStore, and sending it from the
 * client would be a trust boundary this endpoint deliberately avoids.
 */
const latestMessageText = (messages: UIMessage[]): string =>
    messages[messages.length - 1]?.parts
        .filter(isTextUIPart)
        .map((part) => part.text)
        .join('') ?? '';

/**
 * Build the `decisions` payload from the newest message's current step, if
 * that message is the assistant's own paused turn rather than a fresh user
 * prompt. Restricted to parts after the last `step-start` marker — the same
 * scope `lastAssistantMessageIsCompleteWithApprovalResponses` uses to decide
 * whether to auto-resend — so a decision already resolved in an earlier step
 * of the same message is never resubmitted.
 */
const pendingDecisions = (messages: UIMessage[]): Record<string, ChatDecision> | null => {
    const last = messages[messages.length - 1];

    if (last === undefined || last.role !== 'assistant') {
        return null;
    }

    const lastStepStartIndex = last.parts.reduce(
        (index, part, currentIndex) => (part.type === 'step-start' ? currentIndex : index),
        -1,
    );

    const decisions: Record<string, ChatDecision> = {};

    for (const part of last.parts.slice(lastStepStartIndex + 1)) {
        if (isToolUIPart(part) && part.state === 'approval-responded') {
            decisions[part.approval.id] = part.approval.approved
                ? { action: 'approve' }
                : { action: 'reject', result: part.approval.reason };
        }
    }

    return Object.keys(decisions).length > 0 ? decisions : null;
};

/**
 * A failed chat request, carrying the HTTP status alongside the localized
 * message so a consuming component can branch on which failure this is
 * (402 out of AI credits — show a billing CTA; 409 a turn is already
 * streaming — recoverable by waiting; 403/404 someone else's or a deleted
 * conversation) without parsing the message text, which is locale-dependent.
 */
export class ChatRequestError extends Error {
    constructor(
        message: string,
        public readonly status: number,
    ) {
        super(message);
        this.name = 'ChatRequestError';
    }
}

/**
 * Read a translated `message` out of a non-2xx JSON error body — the shape
 * every abort()/gate response on this route uses (402 out of AI credits, 403
 * someone else's conversation, 404 deleted, 409 turn already in progress) —
 * instead of surfacing the raw response body as the error text.
 */
const parseErrorMessage = async (response: Response): Promise<string> => {
    const body: unknown = await response.json().catch(() => null);

    if (body !== null && typeof body === 'object' && 'message' in body) {
        const { message } = body as { message: unknown };

        if (typeof message === 'string' && message !== '') {
            return message;
        }
    }

    return trans('chat.errors.request_failed');
};

const fetchWithReadableErrors: typeof fetch = async (input, init) => {
    const response = await fetch(input, init);

    if (!response.ok) {
        throw new ChatRequestError(await parseErrorMessage(response), response.status);
    }

    return response;
};

/**
 * Consume the chat SSE stream for one workspace conversation.
 *
 * The backend accepts `message` XOR `decisions` (never both) at
 * `POST /chat/{conversation}`, streamed back over the Vercel AI SDK UI
 * message protocol. This composable reshapes `useChat`'s default
 * `{ messages }` request body into that contract via a custom transport, and
 * resumes a run paused on a `tool-approval-request` part through
 * `submitDecisions`, which drives the SDK's own `addToolApprovalResponse` so
 * the resend replays through the same stream-processing pipeline as a normal
 * turn. Request failures surface as `ChatRequestError` on `error`, carrying
 * the HTTP status for callers that need to branch on it.
 */
export const useConversationChat = (conversationId: string, initialMessages: UIMessage[] = []) => {
    const transport = new DefaultChatTransport<UIMessage>({
        api: store.url({ conversation: conversationId }),
        credentials: 'same-origin',
        fetch: fetchWithReadableErrors,
        prepareSendMessagesRequest: ({ messages }) => {
            const decisions = pendingDecisions(messages);

            return {
                body: decisions !== null ? { decisions } : { message: latestMessageText(messages) },
                headers: requestHeaders(),
            };
        },
    });

    const chat = useChat({
        transport,
        messages: initialMessages,
        sendAutomaticallyWhen: lastAssistantMessageIsCompleteWithApprovalResponses,
    });

    /**
     * Resolve one or more paused tool approvals. Each decision is recorded
     * locally through `addToolApprovalResponse`; once every pending approval
     * in the current step has a decision, `sendAutomaticallyWhen` fires the
     * resend automatically — which posts `{ decisions }` to the same route
     * as a normal message, via the transport's `prepareSendMessagesRequest`
     * above.
     */
    const submitDecisions = async (decisions: Record<string, ChatDecision>): Promise<void> => {
        for (const [id, decision] of Object.entries(decisions)) {
            await chat.addToolApprovalResponse({
                id,
                approved: decision.action === 'approve',
                reason: decision.result,
            });
        }
    };

    return { ...chat, submitDecisions };
};
