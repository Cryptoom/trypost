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

const props = defineProps<{
    sources: string[];
    modelValue: string;
}>();

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
    trans(`welcome.referral_source.${value}`);

const isSelected = (value: string): boolean => props.modelValue === value;

const select = (value: string): void => {
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
            :data-testid="`welcome-source-${source}`"
            :dusk="`welcome-source-${source}`"
            :class="[
                'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm transition-colors',
                isSelected(source)
                    ? 'border-primary/40 bg-primary/10 text-foreground'
                    : 'border-border bg-background text-foreground hover:bg-muted',
            ]"
            @click="select(source)"
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
            <span>{{ sourceLabel(source) }}</span>
            <IconCheck
                v-if="isSelected(source)"
                class="size-3.5 text-primary"
                stroke-width="2.5"
            />
        </button>
    </div>
</template>
