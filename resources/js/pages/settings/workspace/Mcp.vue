<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconCopy, IconExternalLink } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import ChatgptIcon from '@/components/mcp/icons/ChatgptIcon.vue';
import ClaudeIcon from '@/components/mcp/icons/ClaudeIcon.vue';
import CursorIcon from '@/components/mcp/icons/CursorIcon.vue';
import OtherClientsIcon from '@/components/mcp/icons/OtherClientsIcon.vue';
import VscodeIcon from '@/components/mcp/icons/VscodeIcon.vue';
import PageHeader from '@/components/PageHeader.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useWorkspaceSettingsTabs } from '@/composables/useWorkspaceSettingsTabs';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { copyToClipboard } from '@/lib/utils';
import { disconnect as mcpDisconnect } from '@/routes/app/mcp';

interface ConnectedClient {
    client_id: string;
    name: string;
    last_used_at: string | null;
}

const props = defineProps<{
    mcpUrl: string;
    docsUrl: string;
    connectedClients: ConnectedClient[];
}>();

const tabs = useWorkspaceSettingsTabs();

const connectorName = computed(() => trans('mcp.connector_name'));

const configSnippet = computed(() =>
    JSON.stringify(
        { mcpServers: { [connectorName.value]: { url: props.mcpUrl } } },
        null,
        2,
    ),
);

const clients = [
    {
        key: 'claude',
        name: 'Claude',
        description: 'mcp.clients.claude',
        icon: ClaudeIcon,
        bgClass: 'bg-[#D97757]/10',
    },
    {
        key: 'chatgpt',
        name: 'ChatGPT',
        description: 'mcp.clients.chatgpt',
        icon: ChatgptIcon,
        bgClass: 'bg-muted',
    },
    {
        key: 'cursor',
        name: 'Cursor',
        description: 'mcp.clients.cursor',
        icon: CursorIcon,
        bgClass: 'bg-muted',
    },
    {
        key: 'vscode',
        name: 'VS Code',
        description: 'mcp.clients.vscode',
        icon: VscodeIcon,
        bgClass: 'bg-[#0098FF]/10',
    },
    {
        key: 'claude_code',
        name: 'Claude Code',
        description: 'mcp.clients.claude_code',
        icon: ClaudeIcon,
        bgClass: 'bg-[#D97757]/10',
    },
    {
        key: 'other',
        name: 'mcp.clients.other_name',
        description: 'mcp.clients.other',
        icon: OtherClientsIcon,
        bgClass: 'bg-muted',
    },
];

const openClient = ref<string>('');

const onClientToggle = (value: string | string[]): void => {
    openClient.value = Array.isArray(value) ? (value[0] ?? '') : (value ?? '');
};

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const confirmDisconnect = (client: ConnectedClient): void => {
    deleteModal.value?.open({
        url: mcpDisconnect.url({ client: client.client_id }),
        confirmText: client.name,
    });
};
</script>

<template>
    <Head :title="$t('mcp.title')" />

    <AppLayout>
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <PageHeader
                :title="$t('settings.hub.title')"
                :description="$t('settings.hub.description')"
            />

            <SettingsTabsNav :tabs="tabs" active="mcp" />

            <div class="flex max-w-3xl flex-col gap-10">
                <div>
                    <h2 class="text-lg font-semibold">{{ $t('mcp.title') }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ $t('mcp.subtitle') }}
                    </p>
                </div>

                <section class="space-y-4">
                    <div>
                        <h3 class="text-base font-semibold">
                            {{ $t('mcp.connect_title') }}
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ $t('mcp.connect_description') }}
                        </p>
                    </div>

                    <Accordion
                        type="single"
                        collapsible
                        :model-value="openClient"
                        class="space-y-3"
                        @update:model-value="onClientToggle"
                    >
                        <AccordionItem
                            v-for="client in clients"
                            :key="client.key"
                            :value="client.key"
                            class="overflow-hidden rounded-xl border"
                        >
                            <AccordionTrigger class="px-4 hover:no-underline">
                                <div class="flex items-center gap-3 text-left">
                                    <div
                                        class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                                        :class="client.bgClass"
                                    >
                                        <component
                                            :is="client.icon"
                                            class="size-5"
                                        />
                                    </div>
                                    <div>
                                        <div class="text-[15px] font-medium">
                                            {{
                                                client.name.startsWith('mcp.')
                                                    ? $t(client.name)
                                                    : client.name
                                            }}
                                        </div>
                                        <div
                                            class="text-[13px] text-muted-foreground"
                                        >
                                            {{ $t(client.description) }}
                                        </div>
                                    </div>
                                </div>
                            </AccordionTrigger>
                            <AccordionContent class="px-4 pb-4">
                                <div class="space-y-4">
                                    <ol
                                        class="m-0 list-none space-y-3 p-0 text-sm text-muted-foreground"
                                    >
                                        <li class="flex gap-2">
                                            <span
                                                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[11px] font-medium text-foreground"
                                                >1</span
                                            >
                                            <span>{{ $t('mcp.step_add') }}</span>
                                        </li>
                                    </ol>

                                    <div class="grid gap-2">
                                        <Label>{{ $t('mcp.name_label') }}</Label>
                                        <div class="flex items-center gap-2">
                                            <Input
                                                :model-value="connectorName"
                                                readonly
                                                class="flex-1 font-mono text-xs"
                                            />
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                @click="
                                                    copyToClipboard(
                                                        connectorName,
                                                    )
                                                "
                                            >
                                                <IconCopy class="size-4" />
                                            </Button>
                                        </div>
                                    </div>

                                    <div class="grid gap-2">
                                        <Label>{{ $t('mcp.url_label') }}</Label>
                                        <div class="flex items-center gap-2">
                                            <Input
                                                :model-value="mcpUrl"
                                                readonly
                                                class="flex-1 font-mono text-xs"
                                            />
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                @click="copyToClipboard(mcpUrl)"
                                            >
                                                <IconCopy class="size-4" />
                                            </Button>
                                        </div>
                                    </div>

                                    <div class="grid gap-2">
                                        <Label>{{
                                            $t('mcp.config_label')
                                        }}</Label>
                                        <div class="relative">
                                            <pre
                                                class="overflow-x-auto rounded-lg border bg-muted/40 p-3 pr-12 font-mono text-xs"
                                            ><code>{{ configSnippet }}</code></pre>
                                            <Button
                                                variant="outline"
                                                size="icon-sm"
                                                class="absolute top-2 right-2"
                                                @click="
                                                    copyToClipboard(
                                                        configSnippet,
                                                    )
                                                "
                                            >
                                                <IconCopy class="size-4" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </AccordionContent>
                        </AccordionItem>
                    </Accordion>
                </section>

                <section class="space-y-4">
                    <div>
                        <h3 class="text-base font-semibold">
                            {{ $t('mcp.connected_title') }}
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ $t('mcp.connected_description') }}
                        </p>
                    </div>

                    <div
                        v-if="connectedClients.length === 0"
                        class="rounded-xl border px-4 py-6 text-center text-sm text-muted-foreground"
                    >
                        {{ $t('mcp.connected_empty') }}
                    </div>

                    <div v-else class="grid gap-2">
                        <div
                            v-for="client in connectedClients"
                            :key="client.client_id"
                            class="flex items-center justify-between gap-4 rounded-lg border px-4 py-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{ client.name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ $t('mcp.last_used') }}:
                                    {{
                                        client.last_used_at
                                            ? date.diffForHumans(
                                                  client.last_used_at,
                                              )
                                            : $t('mcp.never')
                                    }}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="confirmDisconnect(client)"
                            >
                                {{ $t('mcp.disconnect') }}
                            </Button>
                        </div>
                    </div>
                </section>

                <section class="space-y-4">
                    <div>
                        <h3 class="text-base font-semibold">
                            {{ $t('mcp.documentation_title') }}
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{ $t('mcp.documentation_description') }}
                        </p>
                    </div>
                    <Button
                        as="a"
                        variant="outline"
                        size="sm"
                        target="_blank"
                        :href="docsUrl"
                    >
                        <IconExternalLink class="size-4" />
                        {{ $t('mcp.view_docs') }}
                    </Button>
                </section>
            </div>
        </div>

        <ConfirmDeleteModal
            ref="deleteModal"
            method="delete"
            :title="$t('mcp.disconnect_title')"
            :description="$t('mcp.disconnect_confirm')"
            :action="$t('mcp.disconnect')"
        />
    </AppLayout>
</template>
