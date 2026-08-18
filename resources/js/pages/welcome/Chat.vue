<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconArrowUp } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import NetworkConnectGrid, {
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/components/accounts/NetworkConnectGrid.vue';
import InputError from '@/components/InputError.vue';
import McpPrimarySetup from '@/components/mcp/McpPrimarySetup.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import GoalChips from '@/components/welcome/GoalChips.vue';
import PersonaChips from '@/components/welcome/PersonaChips.vue';
import PlatformChips from '@/components/welcome/PlatformChips.vue';
import PublishMethodChips from '@/components/welcome/PublishMethodChips.vue';
import ReferralChips from '@/components/welcome/ReferralChips.vue';
import WelcomeQuestion from '@/components/welcome/WelcomeQuestion.vue';
import { welcomePlatformLabel } from '@/components/welcome/welcomePlatformLabel';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import date from '@/date';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store as storeConnect } from '@/routes/app/welcome/connect';
import { store as storeGoals } from '@/routes/app/welcome/goals';
import { store as storePersona } from '@/routes/app/welcome/persona';
import { store as storePublishMethod } from '@/routes/app/welcome/publish-method';
import { store as storeReferral } from '@/routes/app/welcome/referral-source';
import { SocialAccountStatus } from '@/types/social-account-status';

type WelcomeStep = 'persona' | 'goals' | 'referral' | 'connect';

type HistoryItem = {
    step: Exclude<WelcomeStep, 'connect'>;
    values: string[];
};

type ReachNetwork = {
    value: string;
    label: string;
    views: number;
};

type LatestPost = {
    id: string;
    caption: string | null;
    media_url: string | null;
    permalink: string | null;
    published_at: string | null;
    impressions: number | null;
    reach: {
        network: string;
        network_value: string;
        others: ReachNetwork[];
        each_views: number;
        extra_views: number;
    };
};

const props = withDefaults(
    defineProps<{
        step: WelcomeStep;
        history: HistoryItem[];
        personas?: string[];
        selectedPersona?: string | null;
        goals?: string[];
        selectedGoals?: string[] | null;
        sources?: string[];
        selectedReferral?: string | null;
        publishMethods?: string[];
        selectedPublishMethod?: string | null;
        platforms?: AvailablePlatform[];
        accounts?: ConnectedAccount[];
        latestPostNetwork?: string | null;
        latestPost?: LatestPost | null;
        mcpUrl?: string;
    }>(),
    {
        personas: () => [],
        selectedPersona: null,
        goals: () => [],
        selectedGoals: () => [],
        sources: () => [],
        selectedReferral: null,
        publishMethods: () => [],
        selectedPublishMethod: null,
        platforms: () => [],
        accounts: () => [],
        latestPostNetwork: null,
        mcpUrl: '',
    },
);

const stepNumber: Record<WelcomeStep, number> = {
    persona: 1,
    goals: 2,
    referral: 3,
    connect: 4,
};

const visibleHistory = computed((): HistoryItem[] =>
    props.history.filter(
        (item) => stepNumber[item.step] < stepNumber[props.step],
    ),
);

const questionKey: Record<WelcomeStep, string> = {
    persona: 'welcome.title',
    goals: 'welcome.goals_title',
    referral: 'welcome.referral_source_title',
    connect: 'welcome.connect.title',
};

const descriptionKey: Record<WelcomeStep, string> = {
    persona: 'welcome.description',
    goals: 'welcome.goals_description',
    referral: 'welcome.referral_source_description',
    connect: 'welcome.connect.description',
};

const personaForm = useForm({ persona: props.selectedPersona ?? '' });
const goalsForm = useForm<{ goals: string[] }>({
    goals: (props.selectedGoals ?? []).filter((goal) =>
        props.goals.includes(goal),
    ),
});
const referralForm = useForm({
    referral_source: props.selectedReferral ?? '',
});
const publishMethodForm = useForm({
    publish_method: props.selectedPublishMethod ?? '',
});
const connectForm = useForm({});

const hasConnectedAccount = computed((): boolean =>
    props.accounts.some(
        (account) => account.status === SocialAccountStatus.Connected,
    ),
);

const firstConnectedNetwork = computed((): string | null => {
    if (props.latestPostNetwork) {
        return props.latestPostNetwork;
    }

    const account = props.accounts.find(
        (item) => item.status === SocialAccountStatus.Connected,
    );

    return account?.network ?? null;
});

const selectedNetwork = ref<string>(firstConnectedNetwork.value ?? '');

const clearSelectedNetwork = (): void => {
    selectedNetwork.value = '';
};

const isLatestPostLoading = computed(
    (): boolean =>
        props.step === 'connect' &&
        hasConnectedAccount.value &&
        selectedNetwork.value !== '' &&
        props.latestPost === undefined,
);

const showLatestPost = computed(
    (): boolean =>
        props.step === 'connect' &&
        selectedNetwork.value !== '' &&
        props.latestPost != null,
);

const selectedPlatform = computed(
    (): AvailablePlatform | null =>
        props.platforms.find(
            (platform) => platform.value === selectedNetwork.value,
        ) ??
        props.platforms.find(
            (platform) => platform.network === selectedNetwork.value,
        ) ??
        null,
);

const selectedPlatforms = computed((): AvailablePlatform[] => {
    if (selectedPlatform.value === null) {
        return [];
    }

    return props.platforms.filter(
        (platform) => platform.network === selectedPlatform.value?.network,
    );
});

const selectedNetworkNeedsAction = computed((): boolean => {
    if (selectedPlatform.value === null) {
        return false;
    }

    const account = props.accounts.find(
        (item) => item.network === selectedPlatform.value?.network,
    );

    if (account === undefined) {
        return true;
    }

    return (
        account.status === SocialAccountStatus.Disconnected ||
        account.status === SocialAccountStatus.TokenExpired
    );
});

const submitPersona = (): void => {
    if (!personaForm.persona || personaForm.processing) {
        return;
    }

    personaForm.submit(storePersona());
};

const onPersonaSelected = (value: string): void => {
    personaForm.persona = value;
    submitPersona();
};

const submitGoals = (): void => {
    if (goalsForm.goals.length === 0 || goalsForm.processing) {
        return;
    }

    goalsForm.submit(storeGoals());
};

const onGoalsSelected = (value: string[]): void => {
    goalsForm.goals = value;
    submitGoals();
};

const submitReferral = (): void => {
    if (referralForm.referral_source === '' || referralForm.processing) {
        return;
    }

    referralForm.submit(storeReferral());
};

const onReferralSelected = (value: string): void => {
    referralForm.referral_source = value;
    submitReferral();
};

const submitPublishMethod = (): void => {
    if (
        publishMethodForm.publish_method === '' ||
        publishMethodForm.processing
    ) {
        return;
    }

    publishMethodForm.submit(storePublishMethod());
};

const onPublishMethodSelected = (value: string): void => {
    publishMethodForm.publish_method = value;
    submitPublishMethod();
};

const submitConnect = (): void => {
    if (connectForm.processing || !hasConnectedAccount.value) {
        return;
    }

    connectForm.submit(storeConnect());
};

const composerDraft = computed((): string => {
    if (props.step === 'connect' && hasConnectedAccount.value) {
        return trans('welcome.continue');
    }

    return '';
});

const canSubmit = computed(
    (): boolean => hasConnectedAccount.value && !connectForm.processing,
);

const showPlatformPicker = computed(
    (): boolean =>
        props.step === 'connect' &&
        selectedNetwork.value === '' &&
        props.platforms.length > 0,
);

const showConnectAction = computed(
    (): boolean =>
        props.step === 'connect' &&
        selectedNetworkNeedsAction.value &&
        selectedPlatforms.value.length > 0,
);

const showPublishMethod = computed(
    (): boolean =>
        props.step === 'connect' &&
        hasConnectedAccount.value &&
        selectedNetwork.value !== '' &&
        !selectedNetworkNeedsAction.value &&
        !isLatestPostLoading.value,
);

const showMcpSetup = computed(
    (): boolean =>
        showPublishMethod.value &&
        publishMethodForm.publish_method === 'ai',
);

const showComposer = computed(
    (): boolean =>
        showPublishMethod.value && publishMethodForm.publish_method !== '',
);

const showStickyPicker = computed(
    (): boolean =>
        props.step === 'persona' ||
        props.step === 'goals' ||
        props.step === 'referral' ||
        showPlatformPicker.value,
);

const showStickyFooter = computed(
    (): boolean =>
        showStickyPicker.value ||
        showComposer.value ||
                Boolean(
            personaForm.errors.persona ||
                goalsForm.errors.goals ||
                referralForm.errors.referral_source ||
                publishMethodForm.errors.publish_method ||
                connectForm.errors.connect,
        ),
);

const formatPostDate = (value: string | null): string =>
    value ? date.formatDate(value) : '';

const formatCount = (value: number): string =>
    new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 }).format(
        value,
    );

const reachRows = computed((): Array<ReachNetwork & { current?: boolean }> => {
    if (props.latestPost == null) {
        return [];
    }

    return [
        {
            value: props.latestPost.reach.network_value,
            label: props.latestPost.reach.network,
            views: props.latestPost.impressions ?? 0,
            current: true,
        },
        ...props.latestPost.reach.others,
    ];
});

const reachMaxViews = computed((): number =>
    Math.max(1, ...reachRows.value.map((row) => row.views)),
);

const reachBarWidth = (views: number): string =>
    `${Math.max(6, (views / reachMaxViews.value) * 100)}%`;

const pitchViewsCopy = computed((): string => {
    if (props.latestPost == null) {
        return '';
    }

    if (props.latestPost.impressions === null) {
        return trans('welcome.connect.pitch_no_views', {
            network: props.latestPost.reach.network,
        });
    }

    return transChoice(
        'welcome.connect.pitch_views',
        props.latestPost.impressions,
        {
            views: formatCount(props.latestPost.impressions),
            network: props.latestPost.reach.network,
        },
    );
});

const transcriptContent = ref<HTMLElement | null>(null);
let transcriptObserver: ResizeObserver | null = null;

const scrollToBottom = (): void => {
    const top = Math.max(
        document.documentElement.scrollHeight,
        document.body.scrollHeight,
    );

    window.scrollTo({ top, left: 0, behavior: 'auto' });
};

const scheduleScrollToBottom = (): void => {
    void nextTick(() => {
        scrollToBottom();
        requestAnimationFrame(scrollToBottom);
    });
};

onMounted(() => {
    scheduleScrollToBottom();

    if (
        transcriptContent.value === null ||
        typeof ResizeObserver === 'undefined'
    ) {
        return;
    }

    transcriptObserver = new ResizeObserver(() => {
        scrollToBottom();
    });
    transcriptObserver.observe(transcriptContent.value);
});

onBeforeUnmount(() => {
    transcriptObserver?.disconnect();
});

watch(
    () => [
        props.step,
        visibleHistory.value.length,
        selectedNetwork.value,
        isLatestPostLoading.value,
        showLatestPost.value,
        showConnectAction.value,
        showComposer.value,
        showPublishMethod.value,
        showMcpSetup.value,
        props.latestPost === undefined ? 'loading' : (props.latestPost?.id ?? 'none'),
    ],
    () => {
        scheduleScrollToBottom();
    },
);

const pitchMissedCopy = computed((): string => {
    if (props.latestPost == null) {
        return '';
    }

    const others = props.latestPost.reach.others;

    if (others.length === 0) {
        return '';
    }

    const [first, second] = others;

    return transChoice('welcome.connect.pitch_missed', others.length, {
        first: first?.label ?? '',
        second: second?.label ?? '',
        each: formatCount(props.latestPost.reach.each_views),
        extra: formatCount(props.latestPost.reach.extra_views),
    });
});

</script>

<template>
    <Head :title="$t(questionKey[step])" />

    <WelcomeLayout
        size="2xl"
        chat
    >
        <div
            class="mx-auto flex w-full max-w-2xl flex-1 flex-col"
            data-testid="welcome-chat"
            dusk="welcome-chat"
        >
            <div
                class="flex-1 px-2 py-6"
                data-testid="welcome-chat-transcript"
                dusk="welcome-chat-transcript"
            >
                <div
                    ref="transcriptContent"
                    class="flex min-h-full flex-col justify-end gap-5"
                >
                <div
                    v-for="item in visibleHistory"
                    :key="item.step"
                    class="flex flex-col gap-3"
                >
                    <WelcomeQuestion :title="$t(questionKey[item.step])" />
                    <div class="flex justify-end">
                        <PersonaChips
                            v-if="item.step === 'persona'"
                            :personas="item.values"
                            :model-value="item.values[0] ?? ''"
                            readonly
                        />
                        <GoalChips
                            v-else-if="item.step === 'goals'"
                            :goals="item.values"
                            :model-value="item.values"
                            readonly
                        />
                        <ReferralChips
                            v-else
                            :sources="item.values"
                            :model-value="item.values[0] ?? ''"
                            readonly
                        />
                    </div>
                </div>

                <WelcomeQuestion
                    :title="$t(questionKey[step])"
                    :description="$t(descriptionKey[step])"
                />

                <template v-if="step === 'connect' && selectedPlatform">
                    <div class="flex flex-col items-end gap-1">
                        <PlatformChips
                            :platforms="[selectedPlatform]"
                            :model-value="selectedNetwork"
                            readonly
                        />
                        <button
                            type="button"
                            class="px-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                            data-testid="welcome-change-network"
                            dusk="welcome-change-network"
                            @click="clearSelectedNetwork"
                        >
                            {{ $t('welcome.connect.change_network') }}
                        </button>
                    </div>
                    <WelcomeQuestion
                        :title="
                            $t('welcome.connect.follow_up', {
                                network: welcomePlatformLabel(
                                    selectedPlatform.label,
                                ),
                            })
                        "
                    />
                    <div
                        v-if="showConnectAction"
                        class="flex items-start gap-2.5"
                    >
                        <span class="size-7 shrink-0" aria-hidden="true" />
                        <NetworkConnectGrid
                            variant="list"
                            :platforms="selectedPlatforms"
                            :connected-accounts="accounts"
                            data-testid="welcome-connect-grid"
                            dusk="welcome-connect-grid"
                        />
                    </div>
                </template>

                <template v-if="isLatestPostLoading">
                    <WelcomeQuestion
                        data-testid="welcome-latest-post-loading"
                        dusk="welcome-latest-post-loading"
                    >
                        <div class="space-y-3">
                            <Skeleton class="h-4 w-48" />
                            <Skeleton class="aspect-square max-w-sm rounded-2xl" />
                            <Skeleton class="h-4 w-full max-w-sm" />
                            <Skeleton class="h-16 max-w-sm rounded-2xl" />
                        </div>
                    </WelcomeQuestion>
                </template>

                <template v-if="showLatestPost && latestPost">
                    <WelcomeQuestion :title="$t('welcome.connect.latest_post')">
                            <component
                                :is="latestPost.permalink ? 'a' : 'div'"
                                :href="latestPost.permalink ?? undefined"
                                :target="
                                    latestPost.permalink
                                        ? '_blank'
                                        : undefined
                                "
                                :rel="
                                    latestPost.permalink
                                        ? 'noopener noreferrer'
                                        : undefined
                                "
                                class="block max-w-sm overflow-hidden rounded-2xl border-2 border-foreground bg-card shadow-2xs"
                                data-testid="welcome-latest-post"
                                dusk="welcome-latest-post"
                            >
                                <img
                                    v-if="latestPost.media_url"
                                    :src="latestPost.media_url"
                                    alt=""
                                    class="aspect-square w-full object-cover"
                                />
                                <div
                                    v-if="
                                        latestPost.caption ||
                                        latestPost.published_at
                                    "
                                    class="space-y-1 px-4 py-3"
                                >
                                    <p
                                        v-if="latestPost.caption"
                                        class="line-clamp-3 text-sm leading-relaxed text-foreground"
                                    >
                                        {{ latestPost.caption }}
                                    </p>
                                    <p
                                        v-if="latestPost.published_at"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{
                                            formatPostDate(
                                                latestPost.published_at,
                                            )
                                        }}
                                    </p>
                                </div>
                            </component>
                    </WelcomeQuestion>

                    <WelcomeQuestion
                        :title="pitchViewsCopy"
                        data-testid="welcome-reach-pitch"
                        dusk="welcome-reach-pitch"
                    >
                        <div class="space-y-3">
                            <p
                                v-if="pitchMissedCopy"
                                class="text-sm leading-relaxed text-foreground"
                            >
                                {{ pitchMissedCopy }}
                            </p>
                            <div
                                class="max-w-sm space-y-2 rounded-2xl border-2 border-foreground bg-card px-4 py-3 shadow-2xs"
                            >
                                <div
                                    v-for="row in reachRows"
                                    :key="row.value"
                                    class="flex items-center gap-3"
                                >
                                    <img
                                        :src="getPlatformLogo(row.value)"
                                        alt=""
                                        class="size-5 shrink-0"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex items-baseline justify-between gap-2"
                                        >
                                            <p
                                                class="truncate text-xs font-medium text-foreground"
                                            >
                                                {{ row.label }}
                                            </p>
                                            <p
                                                class="shrink-0 text-xs tabular-nums"
                                                :class="
                                                    row.current
                                                        ? 'text-muted-foreground'
                                                        : 'font-medium text-foreground'
                                                "
                                            >
                                                {{ formatCount(row.views) }}
                                            </p>
                                        </div>
                                        <div
                                            class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted"
                                        >
                                            <div
                                                class="h-full rounded-full"
                                                :class="
                                                    row.current
                                                        ? 'bg-muted-foreground/40'
                                                        : 'bg-foreground'
                                                "
                                                :style="{
                                                    width: reachBarWidth(
                                                        row.views,
                                                    ),
                                                }"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p
                                class="text-sm leading-relaxed text-foreground"
                            >
                                {{ $t('welcome.connect.pitch_sales') }}
                            </p>
                        </div>
                    </WelcomeQuestion>
                </template>

                <template v-if="showPublishMethod">
                    <WelcomeQuestion
                        :title="$t('welcome.publish_method.title')"
                        :description="$t('welcome.publish_method.description')"
                    />
                    <PublishMethodChips
                        :methods="publishMethods"
                        :model-value="publishMethodForm.publish_method"
                        :disabled="publishMethodForm.processing"
                        @update:model-value="onPublishMethodSelected"
                    />
                    <WelcomeQuestion
                        v-if="showMcpSetup"
                        :title="$t('welcome.publish_method.mcp')"
                        data-testid="welcome-mcp-setup"
                        dusk="welcome-mcp-setup"
                    >
                        <McpPrimarySetup
                            :mcp-url="mcpUrl"
                            :copied-message="$t('mcp.copied')"
                        />
                    </WelcomeQuestion>
                </template>
                </div>
            </div>

            <div
                v-if="showStickyFooter"
                class="sticky bottom-0 bg-background pt-2 pb-5"
            >
                <div v-if="showStickyPicker" class="mb-3">
                    <PersonaChips
                        v-if="step === 'persona'"
                        :model-value="personaForm.persona"
                        :personas="personas"
                        :disabled="personaForm.processing"
                        @update:model-value="onPersonaSelected"
                    />
                    <GoalChips
                        v-else-if="step === 'goals'"
                        :model-value="goalsForm.goals"
                        :goals="goals"
                        :disabled="goalsForm.processing"
                        @update:model-value="onGoalsSelected"
                    />
                    <ReferralChips
                        v-else-if="step === 'referral'"
                        :model-value="referralForm.referral_source"
                        :sources="sources"
                        :disabled="referralForm.processing"
                        @update:model-value="onReferralSelected"
                    />
                    <PlatformChips
                        v-else-if="showPlatformPicker"
                        v-model="selectedNetwork"
                        :platforms="platforms"
                    />
                </div>

                <InputError
                    v-if="step === 'persona'"
                    :message="personaForm.errors.persona"
                />
                <InputError
                    v-else-if="step === 'goals'"
                    :message="goalsForm.errors.goals"
                />
                <InputError
                    v-else-if="step === 'referral'"
                    :message="referralForm.errors.referral_source"
                />
                <InputError
                    v-else-if="connectForm.errors.connect"
                    :message="connectForm.errors.connect"
                    dusk="welcome-connect-error"
                />
                <InputError
                    v-else
                    :message="publishMethodForm.errors.publish_method"
                />

                <div
                    v-if="showComposer"
                    class="mt-2 flex items-end gap-2 rounded-3xl border-2 border-foreground bg-card p-2 shadow-2xs"
                >
                    <p
                        class="min-h-10 flex-1 px-3 py-2 text-sm leading-relaxed"
                        :class="
                            composerDraft
                                ? 'text-foreground'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ composerDraft }}
                    </p>
                    <Button
                        type="button"
                        size="icon"
                        class="rounded-full"
                        :disabled="!canSubmit"
                        :aria-label="$t('welcome.continue')"
                        data-testid="welcome-start-checkout"
                        dusk="welcome-start-checkout"
                        @click="submitConnect"
                    >
                        <IconArrowUp class="size-5" />
                    </Button>
                </div>
            </div>
        </div>
    </WelcomeLayout>
</template>
