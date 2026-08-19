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

const textPart = (text: string) => ({ type: 'text' as const, text });

/**
 * Rebuild a turn from its stored `parts`, which record the text and the tool
 * calls in the order the model produced them. A tool part names its call
 * only, so its arguments are read back off the message's `tool_calls`.
 */
const partsFromStoredOrder = (message: ChatServerMessage) => {
    const calls = new Map((message.tool_calls ?? []).map((call) => [call.id, call]));

    return (message.parts ?? []).map((part) =>
        part.type === 'text'
            ? textPart(part.text)
            : toolPart(calls.get(part.id) ?? { id: part.id, name: part.name, arguments: null }, message.payloads[part.id] ?? ''),
    );
};

/**
 * Rebuild a turn stored before `parts` existed, from the flat `tool_calls`
 * array and the single `content` string those rows have. Their interleaving
 * was never recorded, so the tool cards are placed before the text: a turn
 * that answered after its calls resolved is the common shape, and it is the
 * only one those columns can express.
 */
const partsFromFlatColumns = (message: ChatServerMessage) => [
    ...(message.tool_calls ?? []).map((call) => toolPart(call, message.payloads[call.id] ?? '')),
    ...(message.content ? [textPart(message.content)] : []),
];

/**
 * Rebuild `useChat`'s initial `UIMessage[]` from a reopened conversation's
 * server-rendered messages, so its tool cards render immediately instead of
 * only the plain text.
 *
 * A turn stored with `parts` replays in the order it actually happened, so a
 * sentence the model said before calling a tool renders above that tool's
 * card and anything it said afterwards renders below it. Rows written before
 * the column existed have no `parts` and fall back to the flat columns.
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
                parts: message.parts?.length ? partsFromStoredOrder(message) : partsFromFlatColumns(message),
            }) as UIMessage,
    );
