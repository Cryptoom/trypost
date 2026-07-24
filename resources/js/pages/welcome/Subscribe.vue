<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconArrowRight } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import { useTracking } from '@/composables/useTracking';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { checkout } from '@/routes/app/welcome';

const props = defineProps<{
    plan: { name: string; interval: string };
}>();

const form = useForm({});

const { trackBeginCheckout } = useTracking();

const submit = (): void => {
    if (form.processing) {
        return;
    }

    trackBeginCheckout({
        name: props.plan.name,
        interval: props.plan.interval,
    });

    form.submit(checkout());
};
</script>

<template>
    <Head :title="$t('welcome.subscribe.title')" />

    <WelcomeLayout
        :title="$t('welcome.subscribe.title')"
        :description="$t('welcome.subscribe.description')"
        :step="4"
    >
        <div class="mx-auto flex w-full max-w-sm flex-col items-center gap-3">
            <Button
                type="button"
                size="lg"
                class="w-full rounded-full"
                :disabled="form.processing"
                @click="submit"
            >
                {{ $t('welcome.continue') }}
                <IconArrowRight class="size-4" />
            </Button>
        </div>
    </WelcomeLayout>
</template>
