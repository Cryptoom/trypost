<script setup lang="ts">
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import { IconCheck, IconCopy, IconLink } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import NetworkConnectGrid, {
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/components/accounts/NetworkConnectGrid.vue';
import OnboardingStepCard from '@/components/onboarding/OnboardingStepCard.vue';
import { Button } from '@/components/ui/button';
import { useOnboardingStatusEcho } from '@/composables/echo/useOnboardingStatusEcho';
import AppLayout from '@/layouts/AppLayout.vue';
import { copyToClipboard } from '@/lib/utils';
import { complete, dismiss } from '@/routes/app/onboarding';
import { create as createPost } from '@/routes/app/posts';

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
    canDismiss: boolean;
    mcpUrl: string;
    mcpClients: McpClient[];
    samplePrompt: string;
    platforms: AvailablePlatform[];
    accounts: ConnectedAccount[];
}>();

const page = usePage();
const firstName = computed(() => {
    const name = (page.props.auth.user?.name ?? '').trim();

    return name === '' ? '' : (name.split(/\s+/)[0] ?? '');
});

const dismissForm = useForm({});
const completeForm = useForm({});

const onboardingReloadOnly = ['status', 'accounts', 'onboardingResidual'];

// Echo is the fast path; slow poll covers MCP clients when Reverb is down.
useOnboardingStatusEcho(onboardingReloadOnly);
usePoll(5000, { only: onboardingReloadOnly });

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

const themeFor = (
    clientId: string,
): { bg: string; rotate: string } =>
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
                        <template v-if="firstName">
                            {{ $t('onboarding.welcome', { name: firstName }) }}
                        </template>
                        <template v-else>
                            {{ $t('onboarding.welcome_anonymous') }}
                        </template>
                    </h1>
                    <p class="text-sm text-muted-foreground sm:text-base">
                        {{ $t('onboarding.description') }}
                    </p>
                </div>

                <Button
                    v-if="canDismiss && !status.all_complete"
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
                <OnboardingStepCard
                    :done="status.social_connected"
                    :step="1"
                    :title="$t('onboarding.social.title')"
                    :description="$t('onboarding.social.description')"
                    accent-class="bg-sky-100"
                    dusk="onboarding-social"
                >
                    <NetworkConnectGrid
                        :platforms="platforms"
                        :connected-accounts="accounts"
                        grid-class="grid-cols-2 sm:grid-cols-3 xl:grid-cols-5"
                    />
                </OnboardingStepCard>

                <OnboardingStepCard
                    :done="status.mcp_connected"
                    :step="2"
                    :title="$t('onboarding.mcp.title')"
                    :description="$t('onboarding.mcp.description')"
                    accent-class="bg-violet-100"
                    dusk="onboarding-mcp"
                >
                    <div class="space-y-6">
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
                                    <code
                                        class="min-w-0 flex-1 truncate text-sm"
                                    >
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
                    </div>
                </OnboardingStepCard>

                <OnboardingStepCard
                    :done="status.first_post_created"
                    :step="3"
                    :title="$t('onboarding.first_post.title')"
                    :description="$t('onboarding.first_post.description')"
                    accent-class="bg-amber-100"
                    dusk="onboarding-first-post"
                >
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
                                :href="createPost.url()"
                                dusk="create-first-post"
                            >
                                {{
                                    $t('onboarding.first_post.create_button')
                                }}
                            </Link>
                        </Button>
                    </div>
                </OnboardingStepCard>
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
