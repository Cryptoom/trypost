<script setup lang="ts">
import { Head, router, useHttp, usePage, usePoll } from '@inertiajs/vue3';
import { IconCheck, IconLoader2 } from '@tabler/icons-vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { useTracking } from '@/composables/useTracking';
import type { Auth } from '@/types';

import { acknowledgePurchase } from '@/actions/App/Http/Controllers/App/BillingController';
import { calendar, onboarding } from '@/routes/app';

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

// Hold on the processing screen after firing purchase tracking so PostHog and
// the ad pixels (Google/Meta via dataLayer → GTM) have time to send before we
// navigate away — an immediate redirect can cut those requests off.
const REDIRECT_DELAY_MS = 5000;
// After this long without an active subscription, tell the user we are still
// working instead of letting the spinner run silently forever.
const SLOW_NOTICE_MS = 60000;
// Escape hatch when conversion/plan never settles (Stripe outage, stuck open
// session, delayed plan_id) — fire what we have and leave.
const FORCE_CONTINUE_MS = 180000;

const page = usePage();

type Conversion = NonNullable<typeof props.conversion>;

const sessionId = new URLSearchParams(window.location.search).get('session_id');

const { stop } = usePoll(2000, {
    only: [
        'subscriptionActive',
        'redirectToOnboarding',
        'auth',
        'conversion',
        'conversionResolved',
    ],
});

const { trackPurchase } = useTracking();
const purchaseAcknowledgement = useHttp({
    session_id: sessionId ?? '',
});

const finishing = ref(false);
const takingLong = ref(false);
const forceContinue = ref(false);
let redirectTimer: ReturnType<typeof setTimeout> | null = null;
let acknowledgementFallbackTimer: ReturnType<typeof setTimeout> | null = null;
let slowNoticeTimer: ReturnType<typeof setTimeout> | null = null;
let forceContinueTimer: ReturnType<typeof setTimeout> | null = null;

const goNext = (): void => {
    window.location.assign(
        props.redirectToOnboarding ? onboarding.url() : calendar.url(),
    );
};

const authPlan = computed(
    () => (page.props.auth as Auth | undefined)?.plan ?? null,
);

const firePurchaseTracking = (
    plan: { name: string; interval: string } | null,
): void => {
    const conversion = props.conversion as Conversion | null | undefined;

    if (!conversion) {
        return;
    }

    trackPurchase(plan, conversion, props.persona ?? null);
};

const finishAfterDeliveryWindow = (): void => {
    if (!sessionId || !props.conversion) {
        goNext();

        return;
    }

    let navigated = false;
    const navigateOnce = () => {
        if (navigated) {
            return;
        }

        navigated = true;

        if (acknowledgementFallbackTimer) {
            clearTimeout(acknowledgementFallbackTimer);
            acknowledgementFallbackTimer = null;
        }

        goNext();
    };

    acknowledgementFallbackTimer = setTimeout(navigateOnce, REDIRECT_DELAY_MS);
    void purchaseAcknowledgement.post(acknowledgePurchase.url(), {
        onSuccess: navigateOnce,
    });
};

// A verified conversion is re-delivered by the server until this client fires
// the transaction event with stable provider IDs and acknowledges it.
const completePurchase = (options: { force?: boolean } = {}) => {
    const force = options.force === true || forceContinue.value;

    if (finishing.value || !props.subscriptionActive) {
        return;
    }

    if (!force) {
        if (!authPlan.value || !props.conversionResolved) {
            return;
        }
    }

    finishing.value = true;
    stop();
    router.cancelAll();

    if (slowNoticeTimer) {
        clearTimeout(slowNoticeTimer);
        slowNoticeTimer = null;
    }

    if (forceContinueTimer) {
        clearTimeout(forceContinueTimer);
        forceContinueTimer = null;
    }

    takingLong.value = false;

    firePurchaseTracking(authPlan.value);

    redirectTimer = setTimeout(finishAfterDeliveryWindow, REDIRECT_DELAY_MS);
};

const continueNow = () => {
    if (!props.subscriptionActive) {
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

    forceContinue.value = true;
    completePurchase({ force: true });
};

watch(
    [
        () => props.subscriptionActive,
        authPlan,
        () => props.conversionResolved,
        () => props.conversion,
        forceContinue,
    ],
    () => {
        completePurchase();
    },
);

onMounted(() => {
    if (props.subscriptionActive) {
        completePurchase();
    }

    slowNoticeTimer = setTimeout(() => {
        if (!finishing.value) {
            takingLong.value = true;
        }
    }, SLOW_NOTICE_MS);

    forceContinueTimer = setTimeout(() => {
        if (!finishing.value && props.subscriptionActive) {
            forceContinue.value = true;
            completePurchase({ force: true });
        } else if (!finishing.value) {
            takingLong.value = true;
        }
    }, FORCE_CONTINUE_MS);
});

onUnmounted(() => {
    if (redirectTimer) {
        clearTimeout(redirectTimer);
    }

    if (acknowledgementFallbackTimer) {
        clearTimeout(acknowledgementFallbackTimer);
    }

    if (slowNoticeTimer) {
        clearTimeout(slowNoticeTimer);
    }

    if (forceContinueTimer) {
        clearTimeout(forceContinueTimer);
    }
});
</script>

<template>
    <Head :title="$t('billing.processing.page_title')" />

    <section
        class="relative flex min-h-screen items-center justify-center overflow-hidden bg-background px-6"
    >
        <!-- Dot pattern overlay -->
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

        <!-- Soft violet glow blobs -->
        <div
            class="pointer-events-none absolute -top-24 -left-24 size-[440px] rounded-full bg-violet-200/50 blur-3xl"
        />
        <div
            class="pointer-events-none absolute -right-24 -bottom-32 size-[440px] rounded-full bg-fuchsia-200/30 blur-3xl"
        />

        <!-- Mockup window -->
        <div
            class="relative w-full max-w-md -rotate-1 overflow-hidden rounded-xl border-2 border-foreground bg-card shadow-xl"
        >
            <!-- Title bar -->
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

            <!-- Body -->
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
