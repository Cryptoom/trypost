<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowRight, IconSparkles, IconX } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { onboarding } from '@/routes/app';
import { dismiss } from '@/routes/app/onboarding';
</script>

<template>
    <section
        class="m-4 flex flex-col gap-4 rounded-xl border-2 border-foreground bg-violet-100 p-4 shadow-2xs sm:flex-row sm:items-center"
        dusk="onboarding-residual-banner"
    >
        <div class="flex min-w-0 flex-1 items-start gap-3">
            <span
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl border-2 border-foreground bg-violet-200"
            >
                <IconSparkles class="size-4" stroke-width="2.5" />
            </span>
            <div class="min-w-0">
                <p class="font-semibold">
                    {{ $t('onboarding.residual.title') }}
                </p>
                <p class="mt-0.5 text-sm text-foreground/70">
                    {{ $t('onboarding.residual.description') }}
                </p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <Button as-child size="sm">
                <Link :href="onboarding.url()" dusk="onboarding-residual-cta">
                    {{ $t('onboarding.residual.cta') }}
                    <IconArrowRight class="size-4" />
                </Link>
            </Button>
            <Button as-child size="icon-sm" variant="ghost">
                <Link
                    :href="dismiss.url()"
                    method="post"
                    as="button"
                    :aria-label="$t('onboarding.residual.dismiss')"
                    dusk="onboarding-residual-dismiss"
                >
                    <IconX class="size-4" />
                </Link>
            </Button>
        </div>
    </section>
</template>
