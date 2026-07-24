<script setup lang="ts">
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconCheck,
    IconCircle,
    IconCopy,
    IconLink,
    IconMessage,
    IconPlugConnected,
    IconSparkles,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';

import NetworkConnectGrid, {
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/components/accounts/NetworkConnectGrid.vue';
import { Button } from '@/components/ui/button';
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

const dismissForm = useForm({});
const completeForm = useForm({});

usePoll(2000, {
    only: ['status', 'accounts'],
});

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
</script>

<template>
    <Head :title="$t('onboarding.title')" />

    <div class="min-h-svh bg-background">
        <div
            class="mx-auto flex w-full max-w-6xl flex-col gap-8 px-6 py-8 md:py-12"
        >
            <header class="flex items-center justify-between gap-4">
                <img
                    src="/images/trypost/logo-light.png"
                    alt="TryPost"
                    class="h-8 w-auto dark:hidden"
                />
                <img
                    src="/images/trypost/logo-dark.png"
                    alt="TryPost"
                    class="hidden h-8 w-auto dark:block"
                />

                <Button
                    type="button"
                    variant="ghost"
                    :disabled="dismissForm.processing"
                    dusk="onboarding-skip"
                    @click="skip"
                >
                    {{ $t('onboarding.skip') }}
                </Button>
            </header>

            <section class="mx-auto flex max-w-2xl flex-col gap-3 text-center">
                <div
                    class="mx-auto inline-flex size-12 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-sm"
                >
                    <IconSparkles class="size-6" stroke-width="2.25" />
                </div>
                <h1 class="text-3xl font-bold tracking-tight md:text-4xl">
                    {{ $t('onboarding.title') }}
                </h1>
                <p class="text-base text-muted-foreground md:text-lg">
                    {{ $t('onboarding.description') }}
                </p>
            </section>

            <div class="grid gap-6">
                <section
                    class="rounded-2xl border-2 border-foreground bg-card p-5 shadow-2xs md:p-7"
                    dusk="onboarding-mcp"
                >
                    <div class="flex items-start gap-4">
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-2xl border-2 border-foreground bg-teal-100"
                        >
                            <IconPlugConnected class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h2 class="text-xl font-bold">
                                        {{ $t('onboarding.mcp.title') }}
                                    </h2>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        {{ $t('onboarding.mcp.description') }}
                                    </p>
                                </div>
                                <IconCheck
                                    v-if="status.mcp_connected"
                                    class="size-6 shrink-0 text-emerald-600"
                                    stroke-width="3"
                                />
                                <IconCircle
                                    v-else
                                    class="size-6 shrink-0 text-muted-foreground/50"
                                />
                            </div>

                            <div class="mt-5 flex flex-col gap-4">
                                <div
                                    class="flex items-center gap-2 rounded-xl border bg-muted/50 p-2 pl-3"
                                >
                                    <IconLink
                                        class="size-4 shrink-0 text-muted-foreground"
                                    />
                                    <code
                                        class="min-w-0 flex-1 truncate text-sm"
                                        >{{ mcpUrl }}</code
                                    >
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        dusk="copy-mcp-url"
                                        @click="copyMcpUrl"
                                    >
                                        <IconCopy class="size-4" />
                                        {{ $t('onboarding.mcp.copy') }}
                                    </Button>
                                </div>

                                <div class="grid gap-3 md:grid-cols-2">
                                    <div
                                        v-for="client in mcpClients"
                                        :key="client.id"
                                        class="rounded-xl border bg-background p-4"
                                    >
                                        <p class="font-semibold">
                                            {{ client.label }}
                                        </p>
                                        <p
                                            class="mt-1 text-sm text-muted-foreground"
                                        >
                                            {{
                                                $t(
                                                    `onboarding.mcp.clients.${client.id}`,
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section
                    class="rounded-2xl border-2 border-foreground bg-card p-5 shadow-2xs md:p-7"
                    dusk="onboarding-social"
                >
                    <div class="flex items-start gap-4">
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-2xl border-2 border-foreground bg-sky-100"
                        >
                            <IconMessage class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h2 class="text-xl font-bold">
                                        {{ $t('onboarding.social.title') }}
                                    </h2>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        {{
                                            $t('onboarding.social.description')
                                        }}
                                    </p>
                                </div>
                                <IconCheck
                                    v-if="status.social_connected"
                                    class="size-6 shrink-0 text-emerald-600"
                                    stroke-width="3"
                                />
                                <IconCircle
                                    v-else
                                    class="size-6 shrink-0 text-muted-foreground/50"
                                />
                            </div>

                            <NetworkConnectGrid
                                class="mt-5"
                                :platforms="platforms"
                                :connected-accounts="accounts"
                                grid-class="grid-cols-2 sm:grid-cols-3 lg:grid-cols-5"
                            />
                        </div>
                    </div>
                </section>

                <section
                    class="rounded-2xl border-2 border-foreground bg-card p-5 shadow-2xs md:p-7"
                    dusk="onboarding-first-post"
                >
                    <div class="flex items-start gap-4">
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-2xl border-2 border-foreground bg-amber-100"
                        >
                            <IconSparkles class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <h2 class="text-xl font-bold">
                                        {{ $t('onboarding.first_post.title') }}
                                    </h2>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        {{
                                            $t(
                                                'onboarding.first_post.description',
                                            )
                                        }}
                                    </p>
                                </div>
                                <IconCheck
                                    v-if="status.first_post_created"
                                    class="size-6 shrink-0 text-emerald-600"
                                    stroke-width="3"
                                />
                                <IconCircle
                                    v-else
                                    class="size-6 shrink-0 text-muted-foreground/50"
                                />
                            </div>

                            <div class="mt-5 rounded-xl border bg-muted/50 p-4">
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <p class="text-sm font-semibold">
                                        {{
                                            $t(
                                                'onboarding.first_post.prompt_label',
                                            )
                                        }}
                                    </p>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        dusk="copy-sample-prompt"
                                        @click="copySamplePrompt"
                                    >
                                        <IconCopy class="size-4" />
                                        {{
                                            $t(
                                                'onboarding.first_post.copy_prompt',
                                            )
                                        }}
                                    </Button>
                                </div>
                                <p class="mt-2 text-sm leading-relaxed">
                                    {{ samplePrompt }}
                                </p>
                            </div>

                            <Button as-child class="mt-4 rounded-full">
                                <Link
                                    :href="createPostUrl"
                                    dusk="create-first-post"
                                >
                                    {{
                                        $t(
                                            'onboarding.first_post.create_button',
                                        )
                                    }}
                                    <IconArrowRight class="size-4" />
                                </Link>
                            </Button>
                        </div>
                    </div>
                </section>
            </div>

            <section
                class="flex flex-col items-center gap-4 rounded-2xl border-2 border-foreground bg-violet-100 p-6 text-center shadow-2xs"
            >
                <div>
                    <h2 class="text-xl font-bold">
                        {{
                            status.all_complete
                                ? $t('onboarding.ready.title')
                                : $t('onboarding.residual.title')
                        }}
                    </h2>
                    <p class="mt-1 text-sm text-foreground/70">
                        {{
                            status.all_complete
                                ? $t('onboarding.ready.description')
                                : $t('onboarding.residual.description')
                        }}
                    </p>
                </div>

                <Button
                    v-if="status.all_complete"
                    type="button"
                    size="lg"
                    class="rounded-full"
                    :disabled="completeForm.processing"
                    dusk="onboarding-continue"
                    @click="continueToTryPost"
                >
                    {{ $t('onboarding.continue') }}
                    <IconArrowRight class="size-4" />
                </Button>
            </section>
        </div>
    </div>
</template>
