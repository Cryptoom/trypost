<script setup lang="ts">
import { IconAlertTriangle, IconBan, IconLoader2, IconTool } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import ChatApprovalCard from '@/components/chat/ChatApprovalCard.vue';
import { resolveToolComponent } from '@/lib/chat/toolComponents';
import type { ChatApprovalDecision, ChatToolInvocation } from '@/types/chat';

const props = withDefaults(
    defineProps<{
        part: ChatToolInvocation;
        /**
         * True while a turn is in flight. Only `prompt`-kind cards receive it:
         * they submit a new user message, and `pages/chat/Index.vue` drops one
         * sent mid-turn. `display` cards have nothing to disable.
         */
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{
    submit: [string];
    decide: [ChatApprovalDecision];
}>();

const TOOL_TYPE_PREFIX = 'tool-';

const toolName = computed<string>(() =>
    props.part.type.startsWith(TOOL_TYPE_PREFIX) ? props.part.type.slice(TOOL_TYPE_PREFIX.length) : props.part.type,
);

const toolLabel = computed<string>(() => {
    const key = `chat.tool_names.${toolName.value}`;
    const label = trans(key);

    return label === key ? toolName.value : label;
});

const toolEntry = computed(() => resolveToolComponent(toolName.value));

const toolComponent = computed(() => toolEntry.value?.component ?? null);

/** Bound only for `prompt` cards, so a `display` card never receives a stray attribute. */
const promptProps = computed<Record<string, unknown>>(() =>
    toolEntry.value?.kind === 'prompt' ? { disabled: props.disabled } : {},
);

type ParsedResult = { kind: 'data'; data: unknown } | { kind: 'error'; message: string } | { kind: 'unreadable' };

/**
 * Every `WorkspaceTool` subclass returns a raw JSON string — either
 * `{"data": ...}` or `{"error": "..."}` (see
 * App\Ai\Tools\WorkspaceTool::json()/error()). A reopened conversation can
 * also replay a read tool whose stored result no longer exists, which
 * resolves to an empty string rather than either shape — read defensively
 * instead of assuming one of the two JSON shapes is always there.
 */
const parsedResult = computed<ParsedResult>(() => {
    const raw = props.part.output;

    if (typeof raw !== 'string' || raw.trim() === '') {
        return { kind: 'unreadable' };
    }

    let value: unknown;

    try {
        value = JSON.parse(raw);
    } catch {
        return { kind: 'unreadable' };
    }

    if (value !== null && typeof value === 'object' && 'error' in value) {
        const message = (value as { error?: unknown }).error;

        return {
            kind: 'error',
            message: typeof message === 'string' && message !== '' ? message : trans('chat.tools.error'),
        };
    }

    if (value !== null && typeof value === 'object' && 'data' in value) {
        return { kind: 'data', data: (value as { data: unknown }).data };
    }

    return { kind: 'data', data: value };
});

const onSubmit = (text: string): void => emit('submit', text);
const onDecide = (decision: ChatApprovalDecision): void => emit('decide', decision);
</script>

<template>
    <div
        class="mt-2"
        data-testid="chat-tool-part"
        :dusk="`chat-tool-part-${toolName}`"
    >
        <div
            v-if="part.state === 'input-streaming' || part.state === 'input-available' || part.state === 'approval-responded'"
            class="flex items-center gap-2 rounded-xl border border-foreground/15 bg-background p-3 text-sm text-muted-foreground"
            data-testid="chat-tool-part-running"
        >
            <IconLoader2 class="size-4 shrink-0 animate-spin" />
            <span>{{ toolLabel }} — {{ $t('chat.tool_card.running') }}</span>
        </div>

        <ChatApprovalCard
            v-else-if="part.state === 'approval-requested'"
            :part="part"
            :tool-name="toolName"
            :tool-label="toolLabel"
            @decide="onDecide"
        />

        <div
            v-else-if="part.state === 'output-denied'"
            class="flex items-center gap-2 rounded-xl border border-foreground/15 bg-background p-3 text-sm text-muted-foreground"
            data-testid="chat-tool-part-denied"
        >
            <IconBan class="size-4 shrink-0" />
            <span>{{ $t('chat.tool_card.denied') }}</span>
        </div>

        <div
            v-else-if="part.state === 'output-error'"
            class="flex items-center gap-2 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
            data-testid="chat-tool-part-error"
        >
            <IconAlertTriangle class="size-4 shrink-0" />
            <span>{{ part.errorText || $t('chat.tools.error') }}</span>
        </div>

        <template v-else-if="part.state === 'output-available'">
            <div
                v-if="parsedResult.kind === 'error'"
                class="flex items-center gap-2 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"
                data-testid="chat-tool-part-error"
            >
                <IconAlertTriangle class="size-4 shrink-0" />
                <span>{{ parsedResult.message }}</span>
            </div>

            <div
                v-else-if="parsedResult.kind === 'unreadable'"
                class="flex items-center gap-2 rounded-xl border border-foreground/15 bg-background p-3 text-sm text-muted-foreground"
                data-testid="chat-tool-part-unreadable"
            >
                <IconAlertTriangle class="size-4 shrink-0" />
                <span>{{ $t('chat.tool_card.unreadable_result') }}</span>
            </div>

            <component
                :is="toolComponent"
                v-else-if="toolComponent"
                :data="parsedResult.kind === 'data' ? parsedResult.data : null"
                v-bind="promptProps"
                @submit="onSubmit"
            />

            <div
                v-else
                class="flex items-center gap-2 rounded-xl border border-foreground/15 bg-background p-3 text-sm text-muted-foreground"
                data-testid="chat-tool-part-unknown"
            >
                <IconTool class="size-4 shrink-0" />
                <span>{{ toolLabel }} — {{ $t('chat.tool_card.unknown_tool') }}</span>
            </div>
        </template>
    </div>
</template>
