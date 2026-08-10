<script setup lang="ts">
import { IconChevronDown, IconChevronUp, IconPlus, IconX } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import InputError from '@/components/InputError.vue';
import { Avatar } from '@/components/ui/avatar';
import { usePageErrors } from '@/composables/usePageErrors';
import { getPlatformLogo } from '@/composables/usePlatformLogo';

interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

const props = withDefaults(
    defineProps<{
        socialAccount: SocialAccount | null;
        meta: Record<string, any>;
        maxLength?: number;
        maxSegments?: number;
        disabled?: boolean;
        previewOnly?: boolean;
    }>(),
    { maxLength: 280, maxSegments: 24, disabled: false, previewOnly: false },
);

const emit = defineEmits<{ 'update:meta': [value: Record<string, any>] }>();

const open = ref(false);

const updateMeta = (patch: Record<string, any>) => emit('update:meta', { ...props.meta, ...patch });

// Derived straight from meta (like every sibling settings component) so it
// never diverges from the persisted/auto-saved state.
const segments = computed<string[]>(() => (Array.isArray(props.meta?.thread_segments) ? (props.meta!.thread_segments as string[]) : []));

const canAddSegment = computed(() => segments.value.length < props.maxSegments);

const addSegment = () => {
    if (!canAddSegment.value) return;
    updateMeta({ thread_segments: [...segments.value, ''] });
};

const removeSegment = (index: number) =>
    updateMeta({ thread_segments: segments.value.filter((_, i) => i !== index) });

const updateSegment = (index: number, value: string) =>
    updateMeta({ thread_segments: segments.value.map((segment, i) => (i === index ? value : segment)) });

const usageFor = (segment: string) => {
    const used = segment.length;
    const ratio = props.maxLength > 0 ? used / props.maxLength : 0;
    const state = ratio > 1 ? 'over' : ratio >= 0.9 ? 'warn' : 'ok';
    return { used, state };
};

const limitClass = (state: string): string => {
    if (state === 'over') return 'border-foreground bg-rose-100 text-rose-700';
    if (state === 'warn') return 'border-foreground bg-amber-100 text-amber-800';
    return 'border-foreground bg-card text-foreground/60';
};

const errors = usePageErrors();
const threadError = computed<string | undefined>(() =>
    Object.entries(errors.value).find(([key]) => key.endsWith('.meta.thread_segments'))?.[1],
);
</script>

<template>
    <div class="rounded-xl border-2 border-foreground bg-card shadow-2xs">
        <button
            type="button"
            data-testid="x-thread-settings-toggle"
            class="flex w-full cursor-pointer items-center justify-between gap-3 p-4 text-sm"
            @click="open = !open"
        >
            <span class="flex min-w-0 items-center gap-2">
                <span class="inline-flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                    <img :src="getPlatformLogo('x')" alt="X" class="size-full object-cover" />
                </span>
                <span class="truncate font-bold text-foreground">{{ $t('posts.form.x.thread') }}</span>
                <span v-if="socialAccount?.display_label" class="truncate font-medium text-foreground/60">·&nbsp;{{ socialAccount.display_label }}</span>
            </span>
            <IconChevronUp v-if="open" class="size-4 shrink-0 text-foreground/60" />
            <IconChevronDown v-else class="size-4 shrink-0 text-foreground/60" />
        </button>

        <div v-if="open" class="space-y-3 border-t-2 border-foreground/10 px-4 pb-4 pt-4">
            <div v-if="socialAccount" class="flex items-center gap-3 rounded-lg bg-foreground/5 p-3">
                <Avatar
                    :src="socialAccount.avatar_url"
                    :name="socialAccount.display_label"
                    class="size-9 shrink-0 rounded-full border-2 border-foreground shadow-2xs"
                />
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-black uppercase tracking-widest text-foreground/60">{{ $t('posts.form.discord.posting_to') }}</p>
                    <p class="truncate text-sm font-bold text-foreground">{{ socialAccount.display_label }}</p>
                </div>
            </div>

            <div
                v-for="(segment, index) in segments"
                :key="index"
                class="space-y-1.5 rounded-lg border-2 border-foreground/20 p-3"
            >
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-black uppercase tracking-widest text-foreground/50">{{ $t('posts.form.x.tweet_n', { n: String(index + 2) }) }}</span>
                    <button
                        type="button"
                        :disabled="disabled"
                        :data-testid="`x-thread-remove-segment-${index}`"
                        class="cursor-pointer text-foreground/50 hover:text-rose-600 disabled:opacity-50"
                        :title="$t('posts.form.x.remove_tweet')"
                        @click="removeSegment(index)"
                    >
                        <IconX class="size-3.5" />
                    </button>
                </div>
                <textarea
                    :value="segment"
                    :disabled="disabled"
                    :placeholder="$t('posts.form.x.tweet_placeholder')"
                    rows="3"
                    :data-testid="`x-thread-segment-input-${index}`"
                    class="w-full rounded-lg border-2 border-foreground/30 bg-card px-3 py-2 text-sm transition-colors hover:border-foreground focus:border-foreground focus:outline-none disabled:opacity-50"
                    @input="updateSegment(index, ($event.target as HTMLTextAreaElement).value)"
                />
                <div class="flex justify-end">
                    <span
                        class="inline-flex items-center rounded-full border-2 px-2 py-0.5 text-[11px] font-bold"
                        :class="limitClass(usageFor(segment).state)"
                        :data-testid="`x-thread-segment-count-${index}`"
                    >
                        {{ usageFor(segment).used }}/{{ maxLength }}
                    </span>
                </div>
            </div>

            <InputError :message="threadError" />

            <button
                type="button"
                :disabled="disabled || !canAddSegment"
                data-testid="x-thread-add-segment"
                class="inline-flex cursor-pointer items-center gap-1 text-xs font-bold text-foreground/70 hover:text-foreground disabled:opacity-50"
                @click="addSegment"
            >
                <IconPlus class="size-3.5" />
                {{ $t('posts.form.x.add_tweet') }}
            </button>
            <p v-if="!canAddSegment" class="text-xs text-foreground/50">{{ $t('posts.form.x.thread_limit_reached', { max: String(maxSegments) }) }}</p>
        </div>
    </div>
</template>
