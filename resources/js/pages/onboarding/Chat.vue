<script setup lang="ts">
import { Head, useForm, useHttp, usePoll } from '@inertiajs/vue3';
import { IconArrowUp, IconCheck } from '@tabler/icons-vue';
import {
    getActiveLanguage,
    isLoaded,
    loadLanguageAsync,
    trans,
    transChoice,
} from 'laravel-vue-i18n';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

import NetworkConnectGrid, {
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/components/accounts/NetworkConnectGrid.vue';
import InputError from '@/components/InputError.vue';
import McpPrimarySetup from '@/components/mcp/McpPrimarySetup.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import GoalChips from '@/components/onboarding/GoalChips.vue';
import PersonaChips from '@/components/onboarding/PersonaChips.vue';
import PlatformChips from '@/components/onboarding/PlatformChips.vue';
import PublishMethodChips from '@/components/onboarding/PublishMethodChips.vue';
import ReferralChips from '@/components/onboarding/ReferralChips.vue';
import OnboardingChatThread from '@/components/onboarding/OnboardingChatThread.vue';
import OnboardingQuestion from '@/components/onboarding/OnboardingQuestion.vue';
import { onboardingPlatformLabel } from '@/components/onboarding/onboardingPlatformLabel';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { useTypedText } from '@/composables/useTypedText';
import date from '@/date';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import { store as storeConnect } from '@/routes/app/onboarding/connect';
import { store as storeGoals } from '@/routes/app/onboarding/goals';
import { store as storePersona } from '@/routes/app/onboarding/persona';
import { store as storePublishMethod } from '@/routes/app/onboarding/publish-method';
import { store as storeReferral } from '@/routes/app/onboarding/referral-source';
import { SocialAccountStatus } from '@/types/social-account-status';

type OnboardingStep = 'persona' | 'goals' | 'referral' | 'connect';

type HistoryItem = {
    step: Exclude<OnboardingStep, 'connect'>;
    values: string[];
};

type ReachNetwork = {
    value: string;
    label: string;
    views: number;
};

type ConnectedMcpClient = {
    client_id: string;
    name: string;
    can_disconnect: boolean;
    last_used_at: string | null;
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
        step: OnboardingStep;
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
        connectedClients?: ConnectedMcpClient[];
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
        connectedClients: () => [],
    },
);

const stepNumber: Record<OnboardingStep, number> = {
    persona: 1,
    goals: 2,
    referral: 3,
    connect: 4,
};

const questionKey: Record<OnboardingStep, string> = {
    persona: 'onboarding.title',
    goals: 'onboarding.goals_title',
    referral: 'onboarding.referral_source_title',
    connect: 'onboarding.connect.title',
};

const descriptionKey: Record<OnboardingStep, string> = {
    persona: 'onboarding.description',
    goals: 'onboarding.goals_description',
    referral: 'onboarding.referral_source_description',
    connect: 'onboarding.connect.description',
};

type OnboardingState = {
    step: OnboardingStep;
    history: HistoryItem[];
    selectedPersona?: string | null;
    selectedGoals?: string[] | null;
    selectedReferral?: string | null;
    selectedPublishMethod?: string | null;
    platforms?: AvailablePlatform[];
    accounts?: ConnectedAccount[];
    latestPostNetwork?: string | null;
    latestPost?: LatestPost | null;
    mcpUrl?: string;
    connectedClients?: ConnectedMcpClient[];
};

const step = ref<OnboardingStep>(props.step);
const history = ref<HistoryItem[]>([...props.history]);
const platforms = ref<AvailablePlatform[]>([...props.platforms]);
const accounts = ref<ConnectedAccount[]>([...props.accounts]);
const latestPostNetwork = ref<string | null>(props.latestPostNetwork);
const latestPost = ref<LatestPost | null | undefined>(props.latestPost);
const mcpUrl = ref(props.mcpUrl);
const connectedClients = ref<ConnectedMcpClient[]>([
    ...props.connectedClients,
]);

const personaForm = useHttp<{ persona: string }, OnboardingState>({
    persona: props.selectedPersona ?? '',
});
const goalsForm = useHttp<{ goals: string[] }, OnboardingState>({
    goals: (props.selectedGoals ?? []).filter((goal) =>
        props.goals.includes(goal),
    ),
});
const referralForm = useHttp<{ referral_source: string }, OnboardingState>({
    referral_source: props.selectedReferral ?? '',
});
const publishMethodForm = useHttp<{ publish_method: string }, OnboardingState>({
    publish_method: props.selectedPublishMethod ?? '',
});
const connectForm = useForm({});
const question = useTypedText();

const questionCopy = (
    onboardingStep: OnboardingStep,
): { title: string; description: string } => ({
    title: trans(questionKey[onboardingStep]),
    description: trans(descriptionKey[onboardingStep]),
});

const ensureLanguage = async (): Promise<void> => {
    if (isLoaded()) {
        return;
    }

    await loadLanguageAsync(getActiveLanguage());
};

const visibleHistory = computed((): HistoryItem[] =>
    history.value.filter(
        (item) => stepNumber[item.step] < stepNumber[step.value],
    ),
);

type TranscriptQuestion = {
    kind: 'question';
    id: string;
    step: OnboardingStep;
    live: boolean;
};

type TranscriptAnswer = {
    kind: 'answer';
    id: string;
    item: HistoryItem;
};

const transcript = computed(
    (): Array<TranscriptQuestion | TranscriptAnswer> => {
        const rows: Array<TranscriptQuestion | TranscriptAnswer> = [];

        for (const item of visibleHistory.value) {
            rows.push({
                kind: 'question',
                id: `${item.step}-q`,
                step: item.step,
                live: false,
            });
            rows.push({
                kind: 'answer',
                id: `${item.step}-a`,
                item,
            });
        }

        rows.push({
            kind: 'question',
            id: `${step.value}-q`,
            step: step.value,
            live: true,
        });

        return rows;
    },
);

const isQuestionStreaming = computed(
    (): boolean => question.streaming.value,
);

const liveTitle = computed((): string => question.title.value);

const liveDescription = computed((): string => question.description.value);

type OnboardingSnapshot = {
    step: OnboardingStep;
    history: HistoryItem[];
};

let snapshot: OnboardingSnapshot | null = null;

const rollbackOptimistic = (): void => {
    if (snapshot === null) {
        return;
    }

    step.value = snapshot.step;
    history.value = snapshot.history;
    const copy = questionCopy(snapshot.step);
    question.snap(copy.title, copy.description);
    snapshot = null;
};

const hasConnectedAccount = computed((): boolean =>
    accounts.value.some(
        (account) => account.status === SocialAccountStatus.Connected,
    ),
);

const firstConnectedNetwork = computed((): string | null => {
    if (latestPostNetwork.value) {
        return latestPostNetwork.value;
    }

    const account = accounts.value.find(
        (item) => item.status === SocialAccountStatus.Connected,
    );

    return account?.network ?? null;
});

const selectedNetwork = ref<string>(firstConnectedNetwork.value ?? '');

const connectGrid = ref<{
    startConnect: (platformValue: string) => void;
} | null>(null);

const onNetworkSelected = (value: string): void => {
    selectedNetwork.value = value;
    connectGrid.value?.startConnect(value);
};

const clearSelectedNetwork = (): void => {
    selectedNetwork.value = '';
};

const isLatestPostLoading = computed(
    (): boolean =>
        step.value === 'connect' &&
        hasConnectedAccount.value &&
        selectedNetwork.value !== '' &&
        latestPost.value === undefined,
);

const showLatestPost = computed(
    (): boolean =>
        step.value === 'connect' &&
        selectedNetwork.value !== '' &&
        latestPost.value != null,
);

const selectedPlatform = computed(
    (): AvailablePlatform | null =>
        platforms.value.find(
            (platform) => platform.value === selectedNetwork.value,
        ) ??
        platforms.value.find(
            (platform) => platform.network === selectedNetwork.value,
        ) ??
        null,
);

const selectedPlatforms = computed((): AvailablePlatform[] => {
    if (selectedPlatform.value === null) {
        return [];
    }

    return platforms.value.filter(
        (platform) => platform.network === selectedPlatform.value?.network,
    );
});

const selectedNetworkNeedsAction = computed((): boolean => {
    if (selectedPlatform.value === null) {
        return false;
    }

    const account = accounts.value.find(
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

const advanceOptimistically = (
    item: HistoryItem,
    next: OnboardingStep,
): void => {
    snapshot = {
        step: step.value,
        history: [...history.value],
    };
    history.value = [
        ...history.value.filter((row) => row.step !== item.step),
        item,
    ];
    step.value = next;
    question.snap('', '');
    void nextTick(() => {
        const copy = questionCopy(next);
        void question.play(copy.title, copy.description);
    });
};

const applyOnboardingState = (state: OnboardingState, stream = true): void => {
    snapshot = null;
    step.value = state.step;
    history.value = state.history;
    personaForm.persona = state.selectedPersona ?? personaForm.persona;
    goalsForm.goals = state.selectedGoals ?? goalsForm.goals;
    referralForm.referral_source =
        state.selectedReferral ?? referralForm.referral_source;
    publishMethodForm.publish_method =
        state.selectedPublishMethod ?? publishMethodForm.publish_method;

    if (state.platforms) {
        platforms.value = state.platforms;
    }

    if (state.accounts) {
        accounts.value = state.accounts;
    }

    if (state.latestPostNetwork !== undefined) {
        latestPostNetwork.value = state.latestPostNetwork;
    }

    if (state.latestPost !== undefined) {
        latestPost.value = state.latestPost;
    }

    if (state.mcpUrl) {
        mcpUrl.value = state.mcpUrl;
    }

    if (state.connectedClients) {
        connectedClients.value = state.connectedClients;
    }

    if (!stream) {
        return;
    }

    void nextTick(() => {
        const copy = questionCopy(state.step);
        void question.play(copy.title, copy.description);
    });
};

const submitPersona = async (): Promise<void> => {
    if (!personaForm.persona || personaForm.processing) {
        return;
    }

    try {
        const state = await personaForm.post(storePersona.url());

        if (personaForm.hasErrors || !state) {
            rollbackOptimistic();

            return;
        }

        applyOnboardingState(state, false);
    } catch {
        rollbackOptimistic();
    }
};

const onPersonaSelected = (value: string): void => {
    personaForm.persona = value;
    advanceOptimistically({ step: 'persona', values: [value] }, 'goals');
    void submitPersona();
};

const submitGoals = async (): Promise<void> => {
    if (goalsForm.goals.length === 0 || goalsForm.processing) {
        return;
    }

    try {
        const state = await goalsForm.post(storeGoals.url());

        if (goalsForm.hasErrors || !state) {
            rollbackOptimistic();

            return;
        }

        applyOnboardingState(state, false);
    } catch {
        rollbackOptimistic();
    }
};

const onGoalsSelected = (value: string[]): void => {
    goalsForm.goals = value;
    advanceOptimistically({ step: 'goals', values: value }, 'referral');
    void submitGoals();
};

const submitReferral = async (): Promise<void> => {
    if (referralForm.referral_source === '' || referralForm.processing) {
        return;
    }

    try {
        const state = await referralForm.post(storeReferral.url());

        if (referralForm.hasErrors || !state) {
            rollbackOptimistic();

            return;
        }

        applyOnboardingState(state, false);
    } catch {
        rollbackOptimistic();
    }
};

const onReferralSelected = (value: string): void => {
    referralForm.referral_source = value;
    advanceOptimistically(
        { step: 'referral', values: [value] },
        'connect',
    );
    void submitReferral();
};

const submitPublishMethod = async (): Promise<void> => {
    if (
        publishMethodForm.publish_method === '' ||
        publishMethodForm.processing
    ) {
        return;
    }

    try {
        const state = await publishMethodForm.post(storePublishMethod.url());

        if (publishMethodForm.hasErrors || !state) {
            return;
        }

        applyOnboardingState(state, false);
    } catch {
        return;
    }
};

const onPublishMethodSelected = (value: string): void => {
    publishMethodForm.publish_method = value;
    void submitPublishMethod();
};

const submitConnect = (): void => {
    if (connectForm.processing || !hasConnectedAccount.value) {
        return;
    }

    connectForm.submit(storeConnect());
};

const composerDraft = computed((): string => {
    if (step.value === 'connect' && hasConnectedAccount.value) {
        return trans('onboarding.continue');
    }

    return '';
});

const canSubmit = computed(
    (): boolean => hasConnectedAccount.value && !connectForm.processing,
);

const showPlatformPicker = computed(
    (): boolean =>
        step.value === 'connect' &&
        selectedNetwork.value === '' &&
        platforms.value.length > 0,
);

const showConnectAction = computed(
    (): boolean =>
        step.value === 'connect' &&
        selectedNetworkNeedsAction.value &&
        selectedPlatforms.value.length > 0,
);

const showPublishMethod = computed(
    (): boolean =>
        step.value === 'connect' &&
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

const mcpConnected = computed(
    (): boolean => connectedClients.value.length > 0,
);

const connectedClientNames = computed((): string =>
    connectedClients.value.map((client) => client.name).join(', '),
);

const { start: startMcpPoll, stop: stopMcpPoll } = usePoll(
    1000,
    { only: ['connectedClients'] },
    { autoStart: false },
);

watch(
    showMcpSetup,
    (enabled) => {
        if (enabled) {
            startMcpPoll();

            return;
        }

        stopMcpPoll();
    },
    { immediate: true },
);

const showComposer = computed(
    (): boolean =>
        showPublishMethod.value && publishMethodForm.publish_method !== '',
);

const showInlinePicker = computed(
    (): boolean =>
        liveTitle.value !== '' &&
        !isQuestionStreaming.value &&
        (step.value === 'persona' ||
            step.value === 'goals' ||
            step.value === 'referral' ||
            showPlatformPicker.value),
);

const showStickyFooter = computed(
    (): boolean =>
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
    if (latestPost.value == null) {
        return [];
    }

    return [
        {
            value: latestPost.value.reach.network_value,
            label: latestPost.value.reach.network,
            views: latestPost.value.impressions ?? 0,
            current: true,
        },
        ...latestPost.value.reach.others,
    ];
});

const reachMaxViews = computed((): number =>
    Math.max(1, ...reachRows.value.map((row) => row.views)),
);

const reachBarWidth = (views: number): string =>
    `${Math.max(6, (views / reachMaxViews.value) * 100)}%`;

const pitchViewsCopy = computed((): string => {
    if (latestPost.value == null) {
        return '';
    }

    if (latestPost.value.impressions === null) {
        return trans('onboarding.connect.pitch_no_views', {
            network: latestPost.value.reach.network,
        });
    }

    return transChoice(
        'onboarding.connect.pitch_views',
        latestPost.value.impressions,
        {
            views: formatCount(latestPost.value.impressions),
            network: latestPost.value.reach.network,
        },
    );
});

onMounted(() => {
    void (async () => {
        await ensureLanguage();

        const copy = questionCopy(step.value);

        if (props.history.length === 0) {
            void question.play(copy.title, copy.description);
        } else {
            question.snap(copy.title, copy.description);
        }
    })();
});

watch(
    () => props.connectedClients,
    (value) => {
        connectedClients.value = [...value];
    },
);

watch(
    () => props.accounts,
    (value) => {
        accounts.value = [...value];
    },
);

watch(
    () => props.latestPost,
    (value) => {
        latestPost.value = value;
    },
);

watch(
    () => props.latestPostNetwork,
    (value) => {
        latestPostNetwork.value = value;
    },
);

watch(
    () => props.platforms,
    (value) => {
        platforms.value = [...value];
    },
);

const pitchMissedCopy = computed((): string => {
    if (latestPost.value == null) {
        return '';
    }

    const others = latestPost.value.reach.others;

    if (others.length === 0) {
        return '';
    }

    const [first, second] = others;

    return transChoice('onboarding.connect.pitch_missed', others.length, {
        first: first?.label ?? '',
        second: second?.label ?? '',
        each: formatCount(latestPost.value.reach.each_views),
        extra: formatCount(latestPost.value.reach.extra_views),
    });
});

</script>

<template>
    <Head :title="$t(questionKey[step])" />

    <OnboardingLayout
        size="2xl"
        chat
    >
        <div
            class="mx-auto flex w-full max-w-2xl flex-1 flex-col"
            data-testid="onboarding-chat"
            dusk="onboarding-chat"
        >
            <div
                class="flex-1 px-2 pt-[22vh]"
                :class="showComposer ? 'pb-36' : 'pb-28'"
                data-testid="onboarding-chat-transcript"
                dusk="onboarding-chat-transcript"
            >
                <OnboardingChatThread>
                <template
                    v-for="row in transcript"
                    :key="row.id"
                >
                    <div
                        v-if="row.kind === 'question'"
                        class="scroll-mt-24 sm:scroll-mt-32"
                        :data-onboarding-turn="row.live ? 'current' : 'past'"
                    >
                        <OnboardingQuestion
                            v-if="
                                !row.live ||
                                liveTitle !== '' ||
                                isQuestionStreaming
                            "
                            :title="
                                row.live
                                    ? liveTitle
                                    : $t(questionKey[row.step])
                            "
                            :description="
                                row.live
                                    ? liveDescription
                                    : $t(descriptionKey[row.step])
                            "
                            :streaming="row.live && isQuestionStreaming"
                        />
                        <div
                            v-if="row.live && showInlinePicker"
                            class="mt-4 animate-in fade-in slide-in-from-bottom-2 duration-300 motion-reduce:animate-none"
                        >
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
                                :model-value="selectedNetwork"
                                :platforms="platforms"
                                @update:model-value="onNetworkSelected"
                            />
                        </div>
                    </div>
                    <div
                        v-else
                        class="flex justify-end animate-in fade-in slide-in-from-right-4 duration-300 motion-reduce:animate-none"
                    >
                        <PersonaChips
                            v-if="row.item.step === 'persona'"
                            :personas="row.item.values"
                            :model-value="row.item.values[0] ?? ''"
                            readonly
                        />
                        <GoalChips
                            v-else-if="row.item.step === 'goals'"
                            :goals="row.item.values"
                            :model-value="row.item.values"
                            readonly
                        />
                        <ReferralChips
                            v-else
                            :sources="row.item.values"
                            :model-value="row.item.values[0] ?? ''"
                            readonly
                        />
                    </div>
                </template>

                <template v-if="step === 'connect' && selectedPlatform">
                    <div class="flex flex-col items-end gap-1 animate-in fade-in slide-in-from-right-4 duration-300 motion-reduce:animate-none">
                        <PlatformChips
                            :platforms="[selectedPlatform]"
                            :model-value="selectedNetwork"
                            readonly
                        />
                        <button
                            type="button"
                            class="px-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                            data-testid="onboarding-change-network"
                            dusk="onboarding-change-network"
                            @click="clearSelectedNetwork"
                        >
                            {{ $t('onboarding.connect.change_network') }}
                        </button>
                    </div>
                    <OnboardingQuestion
                        :title="
                            $t('onboarding.connect.follow_up', {
                                network: onboardingPlatformLabel(
                                    selectedPlatform.label,
                                ),
                            })
                        "
                    />
                </template>
                <div
                    v-if="step === 'connect'"
                    :class="
                        showConnectAction
                            ? 'flex items-start gap-2.5'
                            : 'hidden'
                    "
                    :data-testid="
                        showConnectAction ? 'onboarding-connect-grid' : undefined
                    "
                    :dusk="
                        showConnectAction ? 'onboarding-connect-grid' : undefined
                    "
                >
                    <span class="size-7 shrink-0" aria-hidden="true" />
                    <NetworkConnectGrid
                        ref="connectGrid"
                        variant="list"
                        :platforms="selectedPlatforms"
                        :connected-accounts="accounts"
                    />
                </div>

                <template v-if="isLatestPostLoading">
                    <OnboardingQuestion
                        data-testid="onboarding-latest-post-loading"
                        dusk="onboarding-latest-post-loading"
                    >
                        <div class="space-y-3">
                            <Skeleton class="h-4 w-48" />
                            <Skeleton class="aspect-square max-w-sm rounded-2xl" />
                            <Skeleton class="h-4 w-full max-w-sm" />
                            <Skeleton class="h-16 max-w-sm rounded-2xl" />
                        </div>
                    </OnboardingQuestion>
                </template>

                <template v-if="showLatestPost && latestPost">
                    <OnboardingQuestion :title="$t('onboarding.connect.latest_post')">
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
                                data-testid="onboarding-latest-post"
                                dusk="onboarding-latest-post"
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
                    </OnboardingQuestion>

                    <OnboardingQuestion
                        :title="pitchViewsCopy"
                        data-testid="onboarding-reach-pitch"
                        dusk="onboarding-reach-pitch"
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
                                    <span
                                        class="inline-flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs"
                                    >
                                        <img
                                            :src="getPlatformLogo(row.value)"
                                            alt=""
                                            class="size-full object-cover"
                                        />
                                    </span>
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
                                {{ $t('onboarding.connect.pitch_sales') }}
                            </p>
                        </div>
                    </OnboardingQuestion>
                </template>

                <template v-if="showPublishMethod">
                    <OnboardingQuestion
                        class="animate-in fade-in slide-in-from-bottom-2 duration-300 motion-reduce:animate-none"
                        :title="$t('onboarding.publish_method.title')"
                        :description="$t('onboarding.publish_method.description')"
                    />
                    <PublishMethodChips
                        class="animate-in fade-in slide-in-from-bottom-2 duration-300 motion-reduce:animate-none"
                        :methods="publishMethods"
                        :model-value="publishMethodForm.publish_method"
                        :disabled="publishMethodForm.processing"
                        @update:model-value="onPublishMethodSelected"
                    />
                    <OnboardingQuestion
                        v-if="showMcpSetup"
                        :title="
                            mcpConnected
                                ? $t('onboarding.publish_method.connected')
                                : $t('onboarding.publish_method.mcp')
                        "
                        :description="
                            mcpConnected
                                ? $t(
                                      'onboarding.publish_method.connected_description',
                                      { name: connectedClientNames },
                                  )
                                : undefined
                        "
                        data-testid="onboarding-mcp-setup"
                        dusk="onboarding-mcp-setup"
                    >
                        <div
                            v-if="mcpConnected"
                            class="space-y-2"
                            data-testid="onboarding-mcp-connected"
                            dusk="onboarding-mcp-connected"
                        >
                            <div
                                v-for="client in connectedClients"
                                :key="client.client_id"
                                class="flex items-center gap-3 rounded-xl border-2 border-foreground bg-emerald-100 p-3 shadow-2xs"
                                :data-testid="`onboarding-mcp-connected-${client.client_id}`"
                            >
                                <span
                                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-foreground bg-emerald-300 shadow-2xs"
                                >
                                    <IconCheck
                                        class="size-4"
                                        stroke-width="3"
                                    />
                                </span>
                                <p
                                    class="truncate text-sm font-bold text-foreground"
                                >
                                    {{ client.name }}
                                </p>
                            </div>
                        </div>
                        <McpPrimarySetup
                            v-if="!mcpConnected"
                            :mcp-url="mcpUrl"
                            :copied-message="$t('mcp.copied')"
                        />
                    </OnboardingQuestion>
                </template>
                </OnboardingChatThread>
            </div>

            <div
                v-if="showStickyFooter"
                class="sticky bottom-0 bg-background pt-2 pb-5"
            >
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
                    dusk="onboarding-connect-error"
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
                        :aria-label="$t('onboarding.continue')"
                        data-testid="onboarding-start-checkout"
                        dusk="onboarding-start-checkout"
                        @click="submitConnect"
                    >
                        <IconArrowUp class="size-5" />
                    </Button>
                </div>
            </div>
        </div>
    </OnboardingLayout>
</template>
