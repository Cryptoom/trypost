<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { IconCheck, IconLoader2 } from '@tabler/icons-vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { useTracking } from '@/composables/useTracking';
import { calendar, onboarding } from '@/routes/app';
import { referralSource } from '@/routes/app/welcome';
import type { Auth } from '@/types';

const props = defineProps<{
    subscriptionActive: boolean;
    redirectToOnboarding: boolean;
    persona?: string | null;
    conversion?: {
        kind: 'purchase' | 'trial';
        value: number;
        currency: string;
        transaction_id: string;
        verified_at: string;
    } | null;
    /** False while Stripe verification is still retryable (open / transient). */
    conversionResolved: boolean;
}>();

const REDIRECT_DELAY_MS = 3000;
const SLOW_NOTICE_MS = 60000;

const page = usePage();

type Conversion = NonNullable<typeof props.conversion>;

// Server consumes conversion on first resolve — keep it for later polls.
const conversion = ref<Conversion | null>(props.conversion ?? null);

watch(
    () => props.conversion,
    (value) => {
        if (value) {
            conversion.value = value;
        }
    },
);

const { start, stop } = usePoll(
    2000,
    {
        only: [
            'subscriptionActive',
            'redirectToOnboarding',
            'auth',
            'conversion',
            'conversionResolved',
        ],
    },
    { autoStart: false },
);

const { trackPurchase } = useTracking();

const finishing = ref(false);
const takingLong = ref(false);
const recoveryAvailable = ref(false);
let redirectTimer: ReturnType<typeof setTimeout> | null = null;
let slowNoticeTimer: ReturnType<typeof setTimeout> | null = null;

const goNext = (): void => {
    window.location.assign(
        props.redirectToOnboarding ? onboarding.url() : calendar.url(),
    );
};

const authPlan = computed(
    () => (page.props.auth as Auth | undefined)?.plan ?? null,
);

const completePurchase = (): void => {
    if (finishing.value || !props.subscriptionActive) {
        return;
    }

    // Subscription is the product gate — analytics must never hold a paying user.
    finishing.value = true;
    stop();
    router.cancelAll();
    takingLong.value = false;

    if (slowNoticeTimer) {
        clearTimeout(slowNoticeTimer);
        slowNoticeTimer = null;
    }

    redirectTimer = setTimeout(goNext, REDIRECT_DELAY_MS);

    try {
        if (conversion.value && authPlan.value) {
            trackPurchase(authPlan.value, conversion.value, props.persona ?? null);
        }
    } catch {
        // Fail-open: redirect still runs.
    }
};

const continueNow = (): void => {
    if (!props.subscriptionActive) {
        if (recoveryAvailable.value) {
            window.location.assign(referralSource.url());

            return;
        }

        router.reload({
            only: [
                'subscriptionActive',
                'redirectToOnboarding',
                'auth',
                'conversion',
                'conversionResolved',
            ],
        });

        return;
    }

    completePurchase();
};

watch(
    () => props.subscriptionActive,
    () => {
        completePurchase();
    },
);

onMounted(() => {
    completePurchase();

    if (!finishing.value) {
        start();
    }

    slowNoticeTimer = setTimeout(() => {
        if (!finishing.value) {
            takingLong.value = true;
            recoveryAvailable.value = !props.subscriptionActive;
        }
    }, SLOW_NOTICE_MS);
});

onUnmounted(() => {
    if (redirectTimer) {
        clearTimeout(redirectTimer);
    }

    if (slowNoticeTimer) {
        clearTimeout(slowNoticeTimer);
    }
});
</script>

<template>
    <Head :title="$t('billing.processing.page_title')" />

    <section
        class="relative flex min-h-screen items-center justify-center overflow-hidden bg-background px-6"
    >
        <div
            class="pointer-events-none absolute inset-0 opacity-[0.06]"
            style="
                background-image: radial-gradient(
                    circle,
                    #0a0a0a 1px,
                    transparent 1px
                );
                background-size: 28px 28px;
            "
        />

        <div
            class="pointer-events-none absolute -top-24 -left-24 size-[440px] rounded-full bg-violet-200/50 blur-3xl"
        />
        <div
            class="pointer-events-none absolute -right-24 -bottom-32 size-[440px] rounded-full bg-fuchsia-200/30 blur-3xl"
        />

        <div
            class="relative w-full max-w-md -rotate-1 overflow-hidden rounded-xl border-2 border-foreground bg-card shadow-xl"
        >
            <div
                class="flex items-center gap-3 border-b-2 border-foreground bg-muted px-4 py-2.5"
            >
                <div class="flex gap-1.5">
                    <span
                        class="size-3 rounded-full border border-foreground bg-rose-300"
                    />
                    <span
                        class="size-3 rounded-full border border-foreground bg-amber-300"
                    />
                    <span
                        class="size-3 rounded-full border border-foreground bg-emerald-300"
                    />
                </div>
                <div
                    class="ms-2 min-w-0 truncate text-[10px] font-bold tracking-widest text-muted-foreground uppercase"
                >
                    trypost.it · checkout
                </div>
                <span
                    class="ms-auto inline-flex items-center gap-1.5 rounded-md border-2 border-foreground bg-foreground px-2 py-0.5 text-[10px] font-black tracking-widest text-background uppercase shadow-2xs"
                >
                    <span class="relative flex size-1.5">
                        <span
                            class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/80 motion-reduce:animate-none"
                        />
                        <span
                            class="relative inline-flex size-1.5 rounded-full bg-emerald-400"
                        />
                    </span>
                    {{ $t('billing.processing.live') }}
                </span>
            </div>

            <div
                class="flex flex-col items-center gap-5 px-8 py-12 text-center"
            >
                <div
                    :class="[
                        'flex size-16 -rotate-2 items-center justify-center rounded-2xl border-2 border-foreground shadow-sm',
                        finishing ? 'bg-emerald-200' : 'bg-violet-200',
                    ]"
                >
                    <IconCheck
                        v-if="finishing"
                        class="size-8 text-emerald-800"
                        stroke-width="3"
                    />
                    <IconLoader2
                        v-else
                        class="size-8 animate-spin text-foreground motion-reduce:animate-none"
                    />
                </div>
                <div role="status" aria-live="polite">
                    <h2
                        class="text-2xl font-normal tracking-tight text-foreground"
                        style="font-family: var(--font-display)"
                    >
                        {{
                            finishing
                                ? $t('billing.processing.success_title')
                                : $t('billing.processing.title')
                        }}
                    </h2>
                    <p class="mt-2 text-sm leading-relaxed text-foreground/70">
                        {{
                            finishing
                                ? $t('billing.processing.success_description')
                                : takingLong
                                  ? $t('billing.processing.taking_long')
                                  : $t('billing.processing.description')
                        }}
                    </p>
                </div>

                <Button
                    v-if="takingLong && !finishing"
                    type="button"
                    data-testid="billing-processing-continue"
                    @click="continueNow"
                >
                    {{
                        subscriptionActive
                            ? $t('billing.processing.continue')
                            : $t('billing.processing.retry')
                    }}
                </Button>
            </div>
        </div>
    </section>
</template>
