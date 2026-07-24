<script setup lang="ts">
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import {
    IconCheck,
    IconCopy,
    IconLink,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import NetworkConnectGrid, {
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/components/accounts/NetworkConnectGrid.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { copyToClipboard } from '@/lib/utils';
import { complete, dismiss } from '@/routes/app/onboarding';

interface OnboardingStatus {
    mcp_connected: boolean;
    social_connected: boolean;
    first_post_created: boolean;
    all_complete: boolean;
    show_residual: boolean;
}

interface McpClient {
    id: string;
    label: string;
    logo: string;
    settings_url: string;
}

const props = defineProps<{
    status: OnboardingStatus;
    mcpUrl: string;
    mcpClients: McpClient[];
    samplePrompt: string;
    platforms: AvailablePlatform[];
    accounts: ConnectedAccount[];
    createPostUrl: string;
}>();

const page = usePage();
const firstName = computed(
    () => (page.props.auth.user?.name ?? '').trim().split(' ')[0],
);

const dismissForm = useForm({});
const completeForm = useForm({});

usePoll(2000, {
    only: ['status', 'accounts'],
});

const mcpClientTheme: Record<string, { bg: string; rotate: string }> = {
    claude: { bg: 'bg-orange-100', rotate: '-rotate-2' },
    chatgpt: { bg: 'bg-black', rotate: 'rotate-1' },
};

const copyMcpUrl = (): void => {
    copyToClipboard(props.mcpUrl, trans('onboarding.mcp.copied'));
};

const copySamplePrompt = (): void => {
    copyToClipboard(props.samplePrompt, trans('onboarding.first_post.copied'));
};

const skip = (): void => {
    if (!dismissForm.processing) {
        dismissForm.submit(dismiss());
    }
};

const continueToTryPost = (): void => {
    if (props.status.all_complete && !completeForm.processing) {
        completeForm.submit(complete());
    }
};

const themeFor = (clientId: string) =>
    mcpClientTheme[clientId] ?? { bg: 'bg-violet-100', rotate: '' };
</script>

<template>
    <Head :title="$t('onboarding.title')" />

    <AppLayout>
        <div
            class="mx-auto flex h-full w-full max-w-5xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 sm:py-10"
        >
            <div
                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="min-w-0 space-y-1.5">
                    <h1
                        class="text-xl font-bold text-foreground sm:text-2xl"
                        dusk="onboarding-welcome"
                    >
                        {{ $t('onboarding.welcome', { name: firstName }) }}
                    </h1>
                    <p class="text-sm text-muted-foreground sm:text-base">
                        {{ $t('onboarding.description') }}
                    </p>
                </div>

                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="shrink-0 self-start text-muted-foreground sm:self-center"
                    :disabled="dismissForm.processing"
                    dusk="onboarding-skip"
                    @click="skip"
                >
                    {{ $t('onboarding.skip') }}
                </Button>
            </div>

            <div class="grid gap-6">
                <section
                    class="overflow-hidden rounded-2xl border-2 border-foreground bg-card shadow-2xs"
                    dusk="onboarding-social"
                >
                    <header
                        :class="[
                            'flex items-center justify-between gap-4 border-b-2 border-foreground px-5 py-4 sm:px-6',
                            status.social_connected
                                ? 'bg-emerald-100'
                                : 'bg-sky-100',
                        ]"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                :class="[
                                    'inline-flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-foreground text-sm font-bold shadow-2xs',
                                    status.social_connected
                                        ? 'bg-emerald-300'
                                        : 'bg-card',
                                ]"
                            >
                                <IconCheck
                                    v-if="status.social_connected"
                                    class="size-4"
                                    stroke-width="3"
                                />
                                <template v-else>1</template>
                            </span>
                            <div class="min-w-0">
                                <h2 class="truncate text-base font-bold">
                                    {{ $t('onboarding.social.title') }}
                                </h2>
                                <p class="truncate text-sm text-foreground/70">
                                    {{ $t('onboarding.social.description') }}
                                </p>
                            </div>
                        </div>
                        <Badge
                            class="shrink-0"
                            :variant="
                                status.social_connected ? 'success' : 'outline'
                            "
                        >
                            {{
                                status.social_connected
                                    ? $t('onboarding.status.complete')
                                    : $t('onboarding.status.todo')
                            }}
                        </Badge>
                    </header>

                    <div class="p-5 sm:p-6">
                        <NetworkConnectGrid
                            :platforms="platforms"
                            :connected-accounts="accounts"
                            grid-class="grid-cols-2 sm:grid-cols-3 xl:grid-cols-5"
                        />
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border-2 border-foreground bg-card shadow-2xs"
                    dusk="onboarding-mcp"
                >
                    <header
                        :class="[
                            'flex items-center justify-between gap-4 border-b-2 border-foreground px-5 py-4 sm:px-6',
                            status.mcp_connected
                                ? 'bg-emerald-100'
                                : 'bg-violet-100',
                        ]"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                :class="[
                                    'inline-flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-foreground text-sm font-bold shadow-2xs',
                                    status.mcp_connected
                                        ? 'bg-emerald-300'
                                        : 'bg-card',
                                ]"
                            >
                                <IconCheck
                                    v-if="status.mcp_connected"
                                    class="size-4"
                                    stroke-width="3"
                                />
                                <template v-else>2</template>
                            </span>
                            <div class="min-w-0">
                                <h2 class="truncate text-base font-bold">
                                    {{ $t('onboarding.mcp.title') }}
                                </h2>
                                <p class="truncate text-sm text-foreground/70">
                                    {{ $t('onboarding.mcp.description') }}
                                </p>
                            </div>
                        </div>
                        <Badge
                            class="shrink-0"
                            :variant="
                                status.mcp_connected ? 'success' : 'outline'
                            "
                        >
                            {{
                                status.mcp_connected
                                    ? $t('onboarding.status.complete')
                                    : $t('onboarding.status.todo')
                            }}
                        </Badge>
                    </header>

                    <div class="space-y-6 p-5 sm:p-6">
                        <div>
                            <div class="mb-3 flex items-center gap-3">
                                <span
                                    class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-foreground text-xs font-bold text-background"
                                    >1</span
                                >
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
                                    <code
                                        class="min-w-0 flex-1 truncate text-sm"
                                        >{{ mcpUrl }}</code
                                    >
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
                                    >2</span
                                >
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

                                    <a
                                        :href="client.settings_url"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex h-10 w-full items-center justify-center rounded-md border-2 border-foreground bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-xs transition-all hover:shadow-sm focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                                        :dusk="`mcp-client-${client.id}`"
                                    >
                                        {{
                                            $t('onboarding.mcp.connect', {
                                                client: client.label,
                                            })
                                        }}
                                    </a>
                                </article>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    class="overflow-hidden rounded-2xl border-2 border-foreground bg-card shadow-2xs"
                    dusk="onboarding-first-post"
                >
                    <header
                        :class="[
                            'flex items-center justify-between gap-4 border-b-2 border-foreground px-5 py-4 sm:px-6',
                            status.first_post_created
                                ? 'bg-emerald-100'
                                : 'bg-amber-100',
                        ]"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                :class="[
                                    'inline-flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-foreground text-sm font-bold shadow-2xs',
                                    status.first_post_created
                                        ? 'bg-emerald-300'
                                        : 'bg-card',
                                ]"
                            >
                                <IconCheck
                                    v-if="status.first_post_created"
                                    class="size-4"
                                    stroke-width="3"
                                />
                                <template v-else>3</template>
                            </span>
                            <div class="min-w-0">
                                <h2 class="truncate text-base font-bold">
                                    {{ $t('onboarding.first_post.title') }}
                                </h2>
                                <p class="truncate text-sm text-foreground/70">
                                    {{
                                        $t('onboarding.first_post.description')
                                    }}
                                </p>
                            </div>
                        </div>
                        <Badge
                            class="shrink-0"
                            :variant="
                                status.first_post_created
                                    ? 'success'
                                    : 'outline'
                            "
                        >
                            {{
                                status.first_post_created
                                    ? $t('onboarding.status.complete')
                                    : $t('onboarding.status.todo')
                            }}
                        </Badge>
                    </header>

                    <div class="p-5 sm:p-6">
                        <div
                            class="rounded-xl border-2 border-foreground bg-amber-50 p-5 shadow-2xs"
                        >
                            <p
                                class="text-xs font-black tracking-widest text-muted-foreground uppercase"
                            >
                                {{ $t('onboarding.first_post.prompt_label') }}
                            </p>
                            <p
                                class="mt-3 text-sm leading-7 text-foreground sm:text-base"
                            >
                                {{ samplePrompt }}
                            </p>
                        </div>

                        <div
                            class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center"
                        >
                            <Button
                                type="button"
                                dusk="copy-sample-prompt"
                                @click="copySamplePrompt"
                            >
                                <IconCopy class="size-4" />
                                {{ $t('onboarding.first_post.copy_prompt') }}
                            </Button>
                            <span
                                class="text-xs font-semibold text-muted-foreground"
                            >
                                {{ $t('onboarding.first_post.or') }}
                            </span>
                            <Button as-child variant="outline">
                                <Link
                                    :href="createPostUrl"
                                    dusk="create-first-post"
                                >
                                    {{
                                        $t(
                                            'onboarding.first_post.create_button',
                                        )
                                    }}
                                </Link>
                            </Button>
                        </div>
                    </div>
                </section>
            </div>

            <section
                v-if="status.all_complete"
                class="flex flex-col items-center gap-4 rounded-2xl border-2 border-foreground bg-violet-100 p-8 text-center shadow-2xs"
            >
                <span
                    class="inline-flex size-12 items-center justify-center rounded-full border-2 border-foreground bg-emerald-200 text-emerald-800 shadow-2xs"
                >
                    <IconCheck class="size-6" stroke-width="3" />
                </span>
                <div>
                    <h2 class="text-xl font-bold">
                        {{ $t('onboarding.ready.title') }}
                    </h2>
                    <p class="mt-1 text-sm text-foreground/70">
                        {{ $t('onboarding.ready.description') }}
                    </p>
                </div>

                <Button
                    type="button"
                    size="lg"
                    :disabled="completeForm.processing"
                    dusk="onboarding-continue"
                    @click="continueToTryPost"
                >
                    {{ $t('onboarding.continue') }}
                </Button>
            </section>
        </div>
    </AppLayout>
</template>
