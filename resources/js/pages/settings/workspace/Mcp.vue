<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { IconCopy, IconExternalLink, IconLink } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
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

interface McpClient {
    id: string;
    label: string;
    logo: string;
    settings_url: string;
}

const props = defineProps<{
    mcpUrl: string;
    docsUrl: string;
    mcpClients: McpClient[];
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

const mcpClientTheme: Record<string, { bg: string; rotate: string }> = {
    claude: { bg: 'bg-orange-100', rotate: '-rotate-2' },
    chatgpt: { bg: 'bg-black', rotate: 'rotate-1' },
};

const themeFor = (clientId: string): { bg: string; rotate: string } =>
    mcpClientTheme[clientId] ?? { bg: 'bg-violet-100', rotate: '' };

const advancedClients = [
    {
        key: 'cursor',
        name: 'Cursor',
        description: 'mcp.clients.cursor',
        icon: CursorIcon,
        tileClass: 'bg-violet-100 -rotate-1',
    },
    {
        key: 'vscode',
        name: 'VS Code',
        description: 'mcp.clients.vscode',
        icon: VscodeIcon,
        tileClass: 'bg-sky-100 rotate-2',
    },
    {
        key: 'claude_code',
        name: 'Claude Code',
        description: 'mcp.clients.claude_code',
        icon: ClaudeIcon,
        tileClass: 'bg-orange-100 rotate-1',
    },
    {
        key: 'other',
        name: 'mcp.clients.other_name',
        description: 'mcp.clients.other',
        icon: OtherClientsIcon,
        tileClass: 'bg-amber-100 -rotate-2',
    },
];

const openClient = ref<string>('');

const onClientToggle = (value: string | string[]): void => {
    openClient.value = Array.isArray(value) ? (value[0] ?? '') : (value ?? '');
};

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const copyMcpUrl = (): void => {
    copyToClipboard(props.mcpUrl, trans('onboarding.mcp.copied'));
};

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
                    <h2 class="text-lg font-bold">{{ $t('mcp.title') }}</h2>
                    <p class="mt-1 text-sm text-foreground/70">
                        {{ $t('mcp.subtitle') }}
                    </p>
                </div>

                <section class="space-y-6">
                    <div>
                        <h3 class="text-base font-bold">
                            {{ $t('mcp.connect_title') }}
                        </h3>
                        <p class="mt-1 text-sm text-foreground/70">
                            {{ $t('mcp.connect_description') }}
                        </p>
                    </div>

                    <div>
                        <div class="mb-3 flex items-center gap-3">
                            <span
                                class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-foreground text-xs font-bold text-background"
                            >
                                1
                            </span>
                            <p class="text-sm font-bold">
                                {{ $t('onboarding.mcp.copy_step') }}
                            </p>
                        </div>
                        <div
                            class="flex flex-col gap-2 rounded-xl border-2 border-foreground bg-background p-2 shadow-2xs sm:flex-row sm:items-center"
                        >
                            <div
                                class="flex min-w-0 flex-1 items-center gap-2 px-2"
                            >
                                <IconLink
                                    class="size-4 shrink-0 text-muted-foreground"
                                />
                                <code class="min-w-0 flex-1 truncate text-sm">
                                    {{ mcpUrl }}
                                </code>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="shrink-0"
                                dusk="copy-mcp-url"
                                @click="copyMcpUrl"
                            >
                                <IconCopy class="size-4" />
                                {{ $t('onboarding.mcp.copy') }}
                            </Button>
                        </div>
                    </div>

                    <div>
                        <div class="mb-3 flex items-center gap-3">
                            <span
                                class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-foreground text-xs font-bold text-background"
                            >
                                2
                            </span>
                            <p class="text-sm font-bold">
                                {{ $t('onboarding.mcp.open_step') }}
                            </p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <article
                                v-for="client in mcpClients"
                                :key="client.id"
                                class="flex flex-col gap-4 rounded-xl border-2 border-foreground bg-card p-4 shadow-2xs"
                            >
                                <div class="flex items-start gap-4">
                                    <span
                                        :class="[
                                            themeFor(client.id).bg,
                                            themeFor(client.id).rotate,
                                            'inline-flex size-12 shrink-0 items-center justify-center rounded-xl border-2 border-foreground shadow-sm',
                                        ]"
                                    >
                                        <img
                                            :src="client.logo"
                                            :alt="client.label"
                                            class="size-7 object-contain"
                                        />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="font-bold">
                                            {{ client.label }}
                                        </h3>
                                        <p
                                            class="mt-1 text-xs leading-relaxed text-muted-foreground"
                                        >
                                            {{
                                                $t(
                                                    `onboarding.mcp.clients.${client.id}`,
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <Button as-child class="w-full">
                                    <a
                                        :href="client.settings_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        :dusk="`mcp-client-${client.id}`"
                                    >
                                        {{
                                            $t('onboarding.mcp.connect', {
                                                client: client.label,
                                            })
                                        }}
                                    </a>
                                </Button>
                            </article>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <h4 class="text-sm font-bold">
                                {{ $t('mcp.other_clients_title') }}
                            </h4>
                            <p class="mt-1 text-sm text-foreground/70">
                                {{ $t('mcp.other_clients_description') }}
                            </p>
                        </div>

                        <Accordion
                            type="single"
                            collapsible
                            :model-value="openClient"
                            class="flex flex-col gap-4 pb-1"
                            @update:model-value="onClientToggle"
                        >
                            <AccordionItem
                                v-for="client in advancedClients"
                                :key="client.key"
                                :value="client.key"
                                class="overflow-hidden rounded-xl border-2 border-foreground bg-card shadow-2xs"
                            >
                                <AccordionTrigger
                                    class="px-5 py-4 hover:no-underline"
                                >
                                    <div
                                        class="flex items-center gap-4 text-left"
                                    >
                                        <div
                                            class="inline-flex size-12 shrink-0 items-center justify-center rounded-xl border-2 border-foreground shadow-sm"
                                            :class="client.tileClass"
                                        >
                                            <component
                                                :is="client.icon"
                                                class="size-6"
                                            />
                                        </div>
                                        <div class="min-w-0">
                                            <div class="font-bold">
                                                {{
                                                    client.name.startsWith(
                                                        'mcp.',
                                                    )
                                                        ? $t(client.name)
                                                        : client.name
                                                }}
                                            </div>
                                            <div
                                                class="mt-0.5 text-sm text-foreground/70"
                                            >
                                                {{ $t(client.description) }}
                                            </div>
                                        </div>
                                    </div>
                                </AccordionTrigger>
                                <AccordionContent class="px-5 pt-1 pb-5">
                                    <div class="space-y-5">
                                        <p class="text-sm font-medium text-foreground/70">
                                            {{ $t('mcp.step_add') }}
                                        </p>

                                        <div class="grid gap-2">
                                            <Label class="font-bold">{{
                                                $t('mcp.name_label')
                                            }}</Label>
                                            <div
                                                class="flex items-center gap-2 rounded-xl border-2 border-foreground bg-background p-2 shadow-2xs"
                                            >
                                                <code
                                                    class="min-w-0 flex-1 truncate px-2 font-mono text-sm"
                                                >
                                                    {{ connectorName }}
                                                </code>
                                                <Button
                                                    variant="outline"
                                                    size="icon"
                                                    class="size-9 shrink-0"
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
                                            <Label class="font-bold">{{
                                                $t('mcp.url_label')
                                            }}</Label>
                                            <div
                                                class="flex items-center gap-2 rounded-xl border-2 border-foreground bg-background p-2 shadow-2xs"
                                            >
                                                <code
                                                    class="min-w-0 flex-1 truncate px-2 font-mono text-sm"
                                                >
                                                    {{ mcpUrl }}
                                                </code>
                                                <Button
                                                    variant="outline"
                                                    size="icon"
                                                    class="size-9 shrink-0"
                                                    @click="
                                                        copyToClipboard(mcpUrl)
                                                    "
                                                >
                                                    <IconCopy class="size-4" />
                                                </Button>
                                            </div>
                                        </div>

                                        <div class="grid gap-2">
                                            <Label class="font-bold">{{
                                                $t('mcp.config_label')
                                            }}</Label>
                                            <div class="relative">
                                                <pre
                                                    class="overflow-x-auto rounded-xl border-2 border-foreground bg-background p-3 pr-14 font-mono text-xs shadow-2xs"
                                                ><code>{{ configSnippet }}</code></pre>
                                                <Button
                                                    variant="outline"
                                                    size="icon"
                                                    class="absolute top-2 right-2 size-9"
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
                    </div>
                </section>

                <section class="space-y-4">
                    <div>
                        <h3 class="text-base font-bold">
                            {{ $t('mcp.connected_title') }}
                        </h3>
                        <p class="mt-1 text-sm text-foreground/70">
                            {{ $t('mcp.connected_description') }}
                        </p>
                    </div>

                    <div
                        v-if="connectedClients.length === 0"
                        class="rounded-xl border-2 border-dashed border-foreground/25 bg-card/40 px-4 py-6 text-center text-sm font-medium text-foreground/60"
                    >
                        {{ $t('mcp.connected_empty') }}
                    </div>

                    <div v-else class="grid gap-3">
                        <div
                            v-for="client in connectedClients"
                            :key="client.client_id"
                            class="flex items-center justify-between gap-4 rounded-xl border-2 border-foreground bg-card px-4 py-3 shadow-2xs"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold">
                                    {{ client.name }}
                                </p>
                                <p
                                    class="text-xs font-medium text-foreground/60"
                                >
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
                        <h3 class="text-base font-bold">
                            {{ $t('mcp.documentation_title') }}
                        </h3>
                        <p class="mt-1 text-sm text-foreground/70">
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
