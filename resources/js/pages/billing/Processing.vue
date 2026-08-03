<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { IconCheck, IconLoader2 } from '@tabler/icons-vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

import { acknowledgePurchase } from '@/actions/App/Http/Controllers/App/BillingController';
import { Button } from '@/components/ui/button';
import { useTracking } from '@/composables/useTracking';
import type { Auth } from '@/types';

import { calendar, onboarding } from '@/routes/app';

const props = defineProps<{
    subscriptionActive: boolean;
    redirectToOnboarding: boolean;
    persona?: string | null;
    conversion?: {
        value: number;
        currency: string;
        transaction_id: string;
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
const storageKey = sessionId ? `trypost.checkout.${sessionId}` : null;

const storeSafely = (key: string, value: string): void => {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Storage unavailable (private mode, quota) — purchase tracking simply
        // falls back to server re-delivery until acknowledge/grace.
    }
};

const readSafely = (key: string): string | null => {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
};

// Persist conversion while verification is still open so a mid-poll reload can
// recover. Once the server says resolved+null, ignore LS for firing — the
// server re-delivers until ack, and LS must not double-fire after tracked.
const cachedConversion = ref<Conversion | null>(((): Conversion | null => {
    if (!storageKey || readSafely(`${storageKey}.tracked`)) {
        return null;
    }

    try {
        const cached = readSafely(storageKey);

        return cached ? (JSON.parse(cached) as Conversion) : null;
    } catch {
        return null;
    }
})());

watch(
    () => props.conversion,
    (value) => {
        if (!value || !storageKey) {
            return;
        }

        storeSafely(storageKey, JSON.stringify(value));
        cachedConversion.value = value;
    },
    { immediate: true },
);

const conversion = computed<Conversion | null>(() => {
    if (props.conversion) {
        return props.conversion;
    }

    // Server still retrying — allow LS recovery. Resolved+null means stop.
    if (!props.conversionResolved) {
        return cachedConversion.value;
    }

    return null;
});

const { stop } = usePoll(2000, {
    only: [
        'subscriptionActive',
        'auth',
        'conversion',
        'conversionResolved',
    ],
});

const { trackPurchase } = useTracking();

const finishing = ref(false);
const takingLong = ref(false);
const forceContinue = ref(false);
let redirectTimer: ReturnType<typeof setTimeout> | null = null;
let slowNoticeTimer: ReturnType<typeof setTimeout> | null = null;
let forceContinueTimer: ReturnType<typeof setTimeout> | null = null;

const goNext = () =>
    router.visit(
        props.redirectToOnboarding ? onboarding.url() : calendar.url(),
    );

const authPlan = computed(
    () => (page.props.auth as Auth | undefined)?.plan ?? null,
);

const acknowledgeIfNeeded = (): void => {
    if (!sessionId) {
        return;
    }

    router.post(
        acknowledgePurchase.url(),
        { session_id: sessionId },
        {
            preserveState: true,
            preserveScroll: true,
        },
    );
};

const firePurchaseTracking = (plan: {
    name: string;
    interval: string;
}): void => {
    const trackedKey = storageKey ? `${storageKey}.tracked` : null;

    if (!conversion.value || (trackedKey && readSafely(trackedKey))) {
        acknowledgeIfNeeded();

        return;
    }

    // Mark tracked before side effects so a reload/second tab cannot double-fire.
    if (trackedKey) {
        storeSafely(trackedKey, '1');
    }

    trackPurchase(plan, conversion.value, props.persona ?? null);
    acknowledgeIfNeeded();
};

// Fires purchase tracking exactly once for a real checkout. Gated on verified
// conversion (server re-delivers until ack). Wait for auth.plan AND
// conversionResolved unless the force-continue escape is active.
const completePurchase = (options: { force?: boolean } = {}) => {
    if (finishing.value || !props.subscriptionActive) {
        return;
    }

    const force = options.force === true || forceContinue.value;
    const plan = authPlan.value;

    if (!force) {
        if (!plan) {
            return;
        }

        if (!props.conversionResolved) {
            return;
        }
    }

    finishing.value = true;
    stop();

    if (slowNoticeTimer) {
        clearTimeout(slowNoticeTimer);
        slowNoticeTimer = null;
    }

    if (forceContinueTimer) {
        clearTimeout(forceContinueTimer);
        forceContinueTimer = null;
    }

    takingLong.value = false;

    firePurchaseTracking(
        plan ?? {
            name: 'Subscription',
            interval: 'monthly',
        },
    );

    redirectTimer = setTimeout(goNext, REDIRECT_DELAY_MS);
};

const continueNow = () => {
    forceContinue.value = true;

    if (props.subscriptionActive) {
        completePurchase({ force: true });

        return;
    }

    // Webhook never flipped the sub — still let the user leave.
    finishing.value = true;
    stop();

    if (slowNoticeTimer) {
        clearTimeout(slowNoticeTimer);
        slowNoticeTimer = null;
    }

    if (forceContinueTimer) {
        clearTimeout(forceContinueTimer);
        forceContinueTimer = null;
    }

    redirectTimer = setTimeout(goNext, REDIRECT_DELAY_MS);
};

watch(
    [
        () => props.subscriptionActive,
        authPlan,
        () => props.conversionResolved,
        conversion,
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
            // Sub never flipped — still offer the button via takingLong UI.
            forceContinue.value = true;
            takingLong.value = true;
        }
    }, FORCE_CONTINUE_MS);
});

onUnmounted(() => {
    if (redirectTimer) {
        clearTimeout(redirectTimer);
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
                <div>
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
                    {{ $t('billing.processing.continue') }}
                </Button>
            </div>
        </div>
    </section>
</template>
