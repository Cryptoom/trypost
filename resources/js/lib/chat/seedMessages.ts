import type { UIMessage } from 'ai';

import type { ChatServerMessage, ChatServerToolCall } from '@/types/chat';

/**
 * Rebuild one tool call into the `tool-<name>` UI part shape `ChatToolPart`
 * expects, in the same terminal state a finished turn always ends in:
 * `output-available`, backed by `payloads[call.id]` — the JSON
 * `ToolReplayer` produced (freshly re-run for read tools, the original
 * stored result for write tools; see `ConversationMessageResource`).
 */
const toolPart = (call: ChatServerToolCall, payload: string) => ({
    type: `tool-${call.name}`,
    toolCallId: call.id,
    state: 'output-available' as const,
    input: call.arguments ?? {},
    output: payload,
});

/**
 * Rebuild `useChat`'s initial `UIMessage[]` from a reopened conversation's
 * server-rendered messages, so its tool cards render immediately instead of
 * only the plain text.
 *
 * Tool parts are placed before the message's own text: a stored assistant
 * turn's `content` is the model's final answer, produced after its tool
 * calls resolved, so this mirrors the order the turn actually happened in.
 * `WorkspaceConversationMessage` only stores one flat `tool_calls` array and
 * one final `content` string per row — a turn that called a tool, read the
 * result, then called a second tool before answering loses that
 * step-by-step interleaving on replay; only the last state of the turn
 * (calls, then answer) can be reconstructed.
 *
 * A separate limitation: `ConversationMessageResource` does not expose
 * whether any of a message's calls are still awaiting approval
 * (`approval_state.pending` isn't part of the resource). A call with no
 * stored result — e.g. a write tool paused for approval when the tab was
 * closed — replays with an empty `output`, which `ChatToolPart` renders as
 * "this result couldn't be read" rather than a live approval prompt the
 * user could act on. Reconstructing that would need the resource to expose
 * `approval_state`, which is outside this change's file scope.
 */
export const buildInitialMessages = (messages: ChatServerMessage[]): UIMessage[] =>
    messages.map(
        (message) =>
            ({
                id: message.id,
                role: message.role,
                parts: [
                    ...(message.tool_calls ?? []).map((call) => toolPart(call, message.payloads[call.id] ?? '')),
                    ...(message.content ? [{ type: 'text' as const, text: message.content }] : []),
                ],
            }) as UIMessage,
    );
