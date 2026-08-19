<script setup lang="ts">
import { IconAlertTriangle, IconCheck, IconX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import { DialogFooter } from '@/components/ui/dialog';
import type { ChatApprovalDecision, ChatToolInvocation } from '@/types/chat';

const props = defineProps<{
    part: ChatToolInvocation;
    toolName: string;
    toolLabel: string;
}>();

const emit = defineEmits<{
    decide: [ChatApprovalDecision];
}>();

/**
 * `PublishPostTool`/`DeletePostTool` pass their reason through
 * `Approval::required(__('chat.approvals.*'))`, which the backend streams on
 * the `tool-approval-request` wire event — but the `ai` package's client
 * reducer only copies `id`/`isAutomatic`/`signature` onto `part.approval`
 * when applying that event, so `reason` never survives to the browser today.
 * `part.approval?.reason` is read anyway so this keeps working the moment
 * that's fixed upstream; until then the same already-reviewed copy is looked
 * up locally by tool name, so the two can't drift.
 */
const REASON_KEYS: Record<string, string> = {
    publish_post: 'chat.approvals.publish',
    delete_post: 'chat.approvals.delete_scheduled',
};

const reason = computed<string>(() => {
    if (props.part.approval?.reason) {
        return props.part.approval.reason;
    }

    const key = REASON_KEYS[props.toolName];

    return trans(key ?? 'chat.approval.generic_reason');
});

const humanizeKey = (key: string): string =>
    key
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

const argumentEntries = computed<[string, string][]>(() => {
    const { input } = props.part;

    if (input === null || typeof input !== 'object') {
        return [];
    }

    return Object.entries(input as Record<string, unknown>).map(([key, value]): [string, string] => [
        humanizeKey(key),
        typeof value === 'string' ? value : JSON.stringify(value),
    ]);
});

/**
 * `addToolApprovalResponse({ id, ... })` keys on the approval id
 * (`part.approval.id`), not the tool call id — the toolCallId fallback only
 * matters if a decision is somehow emitted before the approval chunk lands.
 */
const decisionId = computed(() => props.part.approval?.id ?? props.part.toolCallId);

const decide = (action: ChatApprovalDecision['action']): void => {
    emit('decide', { toolCallId: decisionId.value, action });
};
</script>

<template>
    <div
        class="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-3"
        data-testid="chat-approval-card"
        :dusk="`chat-approval-card-${toolName}`"
    >
        <div class="flex items-start gap-2">
            <IconAlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-700" />
            <div class="space-y-1">
                <p class="text-xs font-bold tracking-wide text-amber-800 uppercase">
                    {{ toolLabel }}
                </p>
                <p class="text-sm text-foreground">
                    {{ reason }}
                </p>
            </div>
        </div>

        <dl
            v-if="argumentEntries.length"
            class="space-y-1 rounded-lg bg-white/60 p-2 text-xs"
            data-testid="chat-approval-card-arguments"
        >
            <div
                v-for="[label, value] in argumentEntries"
                :key="label"
                class="flex items-center justify-between gap-2"
            >
                <dt class="font-semibold text-muted-foreground">{{ label }}</dt>
                <dd class="truncate font-mono text-foreground/80">{{ value }}</dd>
            </div>
        </dl>

        <DialogFooter class="gap-2">
            <Button
                type="button"
                size="sm"
                data-testid="chat-approval-approve"
                :dusk="`chat-approval-approve-${toolName}`"
                @click="decide('approve')"
            >
                <IconCheck class="size-4" />
                {{ $t('chat.approval.approve') }}
            </Button>
            <Button
                type="button"
                variant="outline"
                size="sm"
                data-testid="chat-approval-reject"
                :dusk="`chat-approval-reject-${toolName}`"
                @click="decide('reject')"
            >
                <IconX class="size-4" />
                {{ $t('chat.approval.reject') }}
            </Button>
        </DialogFooter>
    </div>
</template>
