<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { IconCreditCard } from '@tabler/icons-vue';

import WelcomeLayout from '@/layouts/WelcomeLayout.vue';

defineProps<{
    ownerName: string | null;
}>();

// The owner may be checking out in another tab — once the subscription is
// active, the next reload redirects into the app.
usePoll(10000);
</script>

<template>
    <Head :title="$t('welcome.subscription_required_title')" />

    <WelcomeLayout
        :title="$t('welcome.subscription_required_title')"
        :description="$t('welcome.subscription_required_description')"
    >
        <div
            class="flex flex-col items-center gap-4 text-center"
            data-testid="welcome-subscription-required"
        >
            <span
                class="inline-flex size-14 -rotate-2 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-100 shadow-2xs"
            >
                <IconCreditCard class="size-7 text-foreground" />
            </span>
            <p
                v-if="ownerName"
                class="text-sm font-semibold text-muted-foreground"
            >
                {{
                    $t('welcome.subscription_required_owner', {
                        name: ownerName,
                    })
                }}
            </p>
        </div>
    </WelcomeLayout>
</template>
