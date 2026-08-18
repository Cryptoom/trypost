<script setup lang="ts">
import {
    IconArticle,
    IconBrandGithub,
    IconBrandInstagram,
    IconBrandLinkedin,
    IconBrandProducthunt,
    IconBrandReddit,
    IconBrandThreads,
    IconBrandTiktokFilled,
    IconBrandXFilled,
    IconBrandYcombinator,
    IconBrandYoutubeFilled,
    IconCheck,
    IconDots,
    IconListSearch,
    IconSparkles,
    IconUser,
    IconUsers,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { FunctionalComponent } from 'vue';

const props = withDefaults(
    defineProps<{
        sources: string[];
        modelValue: string;
        disabled?: boolean;
        readonly?: boolean;
    }>(),
    {
        disabled: false,
        readonly: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

type SourceMeta = {
    icon?: FunctionalComponent;
    logo?: string;
    iconClass: string;
    badge: string;
};

const sourceMeta: Record<string, SourceMeta> = {
    google: {
        logo: '/images/social/google.svg',
        iconClass: '',
        badge: 'bg-white',
    },
    x: {
        icon: IconBrandXFilled,
        iconClass: 'text-white',
        badge: 'bg-black',
    },
    linkedin: {
        icon: IconBrandLinkedin,
        iconClass: 'text-white',
        badge: 'bg-[#0A66C2]',
    },
    youtube: {
        icon: IconBrandYoutubeFilled,
        iconClass: 'text-white',
        badge: 'bg-[#FF0000]',
    },
    tiktok: {
        icon: IconBrandTiktokFilled,
        iconClass: 'text-white',
        badge: 'bg-black',
    },
    instagram: {
        icon: IconBrandInstagram,
        iconClass: 'text-white',
        badge: 'bg-gradient-to-br from-[#f9ce34] via-[#ee2a7b] to-[#6228d7]',
    },
    threads: {
        icon: IconBrandThreads,
        iconClass: 'text-white',
        badge: 'bg-black',
    },
    reddit: {
        icon: IconBrandReddit,
        iconClass: 'text-white',
        badge: 'bg-[#FF4500]',
    },
    product_hunt: {
        icon: IconBrandProducthunt,
        iconClass: 'text-[#FF6154]',
        badge: 'bg-white',
    },
    github: {
        icon: IconBrandGithub,
        iconClass: 'text-white',
        badge: 'bg-black',
    },
    hacker_news: {
        icon: IconBrandYcombinator,
        iconClass: 'text-white',
        badge: 'bg-[#FF6600]',
    },
    directories: {
        icon: IconListSearch,
        iconClass: 'text-sky-800',
        badge: 'bg-sky-100',
    },
    ai_assistant: {
        icon: IconSparkles,
        iconClass: 'text-violet-700',
        badge: 'bg-violet-100',
    },
    friend: {
        icon: IconUsers,
        iconClass: 'text-emerald-700',
        badge: 'bg-emerald-100',
    },
    founder: {
        icon: IconUser,
        iconClass: 'text-orange-800',
        badge: 'bg-orange-100',
    },
    blog: {
        icon: IconArticle,
        iconClass: 'text-amber-800',
        badge: 'bg-amber-100',
    },
    other: {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    },
};

const metaFor = (value: string): SourceMeta =>
    sourceMeta[value] ?? {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    };

const sourceLabel = (value: string): string =>
    trans(`onboarding.referral_source.${value}`);

const isSelected = (value: string): boolean => props.modelValue === value;

const select = (value: string): void => {
    if (props.disabled || props.readonly) {
        return;
    }

    emit('update:modelValue', value);
};
</script>

<template>
    <div class="flex flex-wrap gap-2">
        <button
            v-for="source in sources"
            :key="source"
            type="button"
            :aria-pressed="isSelected(source)"
            :disabled="props.disabled || props.readonly"
            :data-testid="`onboarding-source-${source}`"
            :dusk="`onboarding-source-${source}`"
            :class="[
                'inline-flex items-center gap-2 rounded-full border-2 border-foreground py-1.5 ps-1.5 pe-3 text-start shadow-2xs',
                props.readonly
                    ? 'cursor-default'
                    : 'cursor-pointer transition-shadow hover:shadow-md disabled:cursor-not-allowed disabled:opacity-60',
                isSelected(source) ? 'bg-violet-100' : 'bg-card',
            ]"
            @click="select(source)"
        >
            <span
                :class="[
                    'inline-flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-foreground shadow-2xs',
                    metaFor(source).badge,
                ]"
            >
                <img
                    v-if="metaFor(source).logo"
                    :src="metaFor(source).logo"
                    alt=""
                    class="size-3.5"
                />
                <component
                    :is="metaFor(source).icon"
                    v-else
                    :class="[metaFor(source).iconClass, 'size-3.5']"
                    stroke-width="2"
                />
            </span>
            <span class="text-sm font-bold tracking-tight text-foreground">
                {{ sourceLabel(source) }}
            </span>
            <span
                v-if="isSelected(source)"
                class="inline-flex size-4 shrink-0 items-center justify-center rounded-full border-2 border-foreground bg-foreground"
            >
                <IconCheck
                    class="size-2.5 text-background"
                    stroke-width="3"
                />
            </span>
        </button>
    </div>
</template>
