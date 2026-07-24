<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconArticle,
    IconBrandGoogle,
    IconBrandInstagram,
    IconBrandLinkedin,
    IconBrandProducthunt,
    IconBrandReddit,
    IconBrandTiktok,
    IconBrandX,
    IconBrandYoutube,
    IconCheck,
    IconDots,
    IconSparkles,
    IconUsers,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { FunctionalComponent } from 'vue';

import { Button } from '@/components/ui/button';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/referral-source';

const props = defineProps<{
    sources: string[];
    selected?: string | null;
}>();

const form = useForm<{ referral_source: string }>({
    referral_source: props.selected ?? '',
});

const sourceMeta: Record<string, { icon: FunctionalComponent; color: string }> =
    {
        google: { icon: IconBrandGoogle, color: 'text-blue-600' },
        x: { icon: IconBrandX, color: 'text-foreground' },
        linkedin: { icon: IconBrandLinkedin, color: 'text-sky-700' },
        youtube: { icon: IconBrandYoutube, color: 'text-red-600' },
        tiktok: { icon: IconBrandTiktok, color: 'text-foreground' },
        instagram: { icon: IconBrandInstagram, color: 'text-fuchsia-600' },
        reddit: { icon: IconBrandReddit, color: 'text-orange-600' },
        product_hunt: { icon: IconBrandProducthunt, color: 'text-orange-500' },
        ai_assistant: { icon: IconSparkles, color: 'text-violet-700' },
        friend: { icon: IconUsers, color: 'text-emerald-600' },
        blog: { icon: IconArticle, color: 'text-amber-600' },
        other: { icon: IconDots, color: 'text-foreground' },
    };

const sourceIcon = (value: string): FunctionalComponent =>
    sourceMeta[value]?.icon ?? IconDots;

const sourceColor = (value: string): string =>
    sourceMeta[value]?.color ?? 'text-foreground';

const sourceLabel = (value: string): string =>
    trans(`welcome.referral_source.${value}`);

const isSelected = (value: string): boolean => form.referral_source === value;

const select = (value: string): void => {
    form.referral_source = value;
};

const submit = (): void => {
    if (form.referral_source === '' || form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.referral_source_title')" />

    <WelcomeLayout
        :title="$t('welcome.referral_source_title')"
        :description="$t('welcome.referral_source_description')"
        :step="3"
        wide
    >
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <button
                v-for="source in sources"
                :key="source"
                type="button"
                :class="[
                    'relative flex cursor-pointer flex-col items-start gap-3 rounded-2xl border-2 border-foreground p-5 text-left shadow-2xs transition-shadow hover:shadow-md',
                    isSelected(source) ? 'bg-violet-100' : 'bg-card',
                ]"
                @click="select(source)"
            >
                <span
                    class="inline-flex size-10 items-center justify-center rounded-2xl border-2 border-foreground bg-card shadow-2xs"
                >
                    <component
                        :is="sourceIcon(source)"
                        :class="[sourceColor(source), 'size-5']"
                        stroke-width="2.25"
                    />
                </span>
                <span
                    class="text-base font-bold tracking-tight text-foreground"
                >
                    {{ sourceLabel(source) }}
                </span>
                <span
                    v-if="isSelected(source)"
                    class="absolute top-4 right-4 inline-flex size-5 items-center justify-center rounded-full border-2 border-foreground bg-foreground"
                >
                    <IconCheck
                        class="size-3 text-background"
                        stroke-width="3"
                    />
                </span>
            </button>
        </div>

        <div class="mx-auto flex w-full max-w-sm flex-col items-center gap-3">
            <Button
                type="button"
                size="lg"
                class="w-full rounded-full"
                :disabled="form.referral_source === '' || form.processing"
                @click="submit"
            >
                {{ $t('welcome.continue') }}
                <IconArrowRight class="size-4" />
            </Button>
        </div>
    </WelcomeLayout>
</template>
