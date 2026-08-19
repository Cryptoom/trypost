<script setup lang="ts">
import { IconCheck, IconSparkles } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, useId, watch } from 'vue';

import ChatAssistantMessage from '@/components/chat/ChatAssistantMessage.vue';
import ChatPostGenerationChoice from '@/components/chat/tools/ChatPostGenerationChoice.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import type {
    ChatPostGenerationAccount,
    ChatPostGenerationCatalog,
    ChatPostGenerationStyle,
} from '@/types/chat';

const props = withDefaults(
    defineProps<{
        data: ChatPostGenerationCatalog | null;
        /**
         * True while a turn is in flight. `pages/chat/Index.vue` drops a
         * message sent mid-turn, so submitting then would latch the card into
         * its sent state for a message that never left — the same reason
         * `ChatComposer` refuses to emit while disabled.
         */
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{
    submit: [string];
}>();

/**
 * One format the card offers, folded across every platform the catalog listed
 * it for: `start_post_generation` repeats a format once per compatible
 * platform (an Instagram format arrives twice when both `instagram` and
 * `instagram-facebook` are connected), but the user is picking a format, not a
 * platform — the platform follows from the account they choose.
 */
interface FormatOption {
    value: string;
    platforms: string[];
    accounts: ChatPostGenerationAccount[];
}

/**
 * Highest image count a carousel format offers, mirroring
 * `ContentType::maxMediaCount()`. Presence in this map is what makes a format
 * a carousel here.
 */
const CAROUSEL_MAX_IMAGES: Record<string, number> = {
    instagram_carousel: 10,
};

/**
 * Formats that always carry exactly one generated image, so there is no image
 * step to show.
 */
const SINGLE_IMAGE_FORMATS = ['facebook_post', 'pinterest_pin', 'instagram_story'];

/**
 * Formats where images are optional, mapped to the highest count offered.
 */
const OPTIONAL_IMAGE_MAX: Record<string, number> = {
    instagram_feed: 1,
    linkedin_post: 4,
    linkedin_page_post: 4,
    x_post: 4,
    threads_post: 4,
    bluesky_post: 4,
    mastodon_post: 4,
};

const DEFAULT_OPTIONAL_IMAGE_MAX = 4;
const DEFAULT_IMAGE_COUNT = 2;
const CAROUSEL_DEFAULT_IMAGE_COUNT = 5;
const SINGLE_IMAGE_FORMAT_COUNT = 1;

/** The steps that read back as the user's own message once answered. */
type RecordedStep = 'format' | 'style' | 'account';

const brandColorsId = useId();

const selectedFormat = ref<string | null>(null);
const selectedStyleKey = ref<string | null>(null);
const imageCount = ref(DEFAULT_IMAGE_COUNT);
const selectedAccountId = ref<string | null>(null);
const submitted = ref(false);

/**
 * The one answered step the user reopened to change. An answered step reads
 * back as their own message instead of staying a live form, so this is the
 * only way back into it — without it, a mis-clicked format would be final.
 */
const editingStep = ref<RecordedStep | null>(null);

/**
 * The choices were already sent — either in this session, or in an earlier one
 * the server marked `spent` because the conversation went on to call
 * `generate_post`. `submitted` alone is component-local, so a reopened
 * conversation would offer a blank, fully interactive card above the post it
 * already produced, and a second submit would bill another generation.
 */
const settled = computed(() => submitted.value || props.data?.spent === true);

/**
 * Null until the user touches the switch, so the catalog's own default stays
 * live: `data` is re-parsed on every parent render and replaced outright when
 * `ToolReplayer` re-runs the tool on reopen, and a value snapshotted at setup
 * would silently outlive the payload it came from.
 */
const brandColorsOverride = ref<boolean | null>(null);

const useBrandColors = computed<boolean>({
    get: () => brandColorsOverride.value ?? props.data?.applies_brand_visuals_default ?? true,
    set: (value: boolean) => {
        brandColorsOverride.value = value;
    },
});

const styles = computed<ChatPostGenerationStyle[]>(() => props.data?.styles ?? []);

const formatOptions = computed<FormatOption[]>(() => {
    const options = new Map<string, FormatOption>();

    for (const entry of props.data?.formats ?? []) {
        const option: FormatOption = options.get(entry.value) ?? {
            value: entry.value,
            platforms: [],
            accounts: [],
        };

        if (! option.platforms.includes(entry.platform)) {
            option.platforms.push(entry.platform);
        }

        for (const account of entry.accounts ?? []) {
            if (! option.accounts.some((existing) => existing.id === account.id)) {
                option.accounts.push(account);
            }
        }

        options.set(entry.value, option);
    }

    return [...options.values()];
});

/**
 * One entry per distinct logo, each keeping the platform it came from so it
 * can carry its own alt text: `instagram` and `instagram-facebook` share a
 * logo file, and the same image stacked twice reads as a rendering glitch.
 */
const formatLogos = (option: FormatOption): Array<{ platform: string; logo: string }> => {
    const seen = new Set<string>();

    return option.platforms.reduce<Array<{ platform: string; logo: string }>>((logos, platform) => {
        const logo = getPlatformLogo(platform);

        if (! seen.has(logo)) {
            seen.add(logo);
            logos.push({ platform, logo });
        }

        return logos;
    }, []);
};

/**
 * Format names are already translated for the wizard this card replaces. A
 * format the catalog gains before the copy does falls back to its raw value
 * rather than printing an i18n key.
 */
const formatLabel = (value: string): string => {
    const key = `posts.create.steps.format.${value}`;
    const label = trans(key);

    return label === key ? value : label;
};

const selectedFormatOption = computed<FormatOption | null>(
    () => formatOptions.value.find((option) => option.value === selectedFormat.value) ?? null,
);

const accountsForFormat = computed(() => selectedFormatOption.value?.accounts ?? []);

/** Styles with no format restriction — the ones a user picks between. */
const freeStyles = computed(() => styles.value.filter((style) => style.supported_formats.length === 0));

/** The style bound to the chosen format (e.g. the carousel's own), if any. */
const formatBoundStyle = computed<ChatPostGenerationStyle | null>(() => {
    const format = selectedFormat.value;

    if (format === null) {
        return null;
    }

    return styles.value.find((style) => style.supported_formats.includes(format)) ?? null;
});

const resolvedStyle = computed<ChatPostGenerationStyle | null>(
    () => formatBoundStyle.value ?? styles.value.find((style) => style.key === selectedStyleKey.value) ?? null,
);

const carouselMaxImages = computed<number | null>(() =>
    selectedFormat.value === null ? null : CAROUSEL_MAX_IMAGES[selectedFormat.value] ?? null,
);

const isSingleImageFormat = computed(
    () => selectedFormat.value !== null && SINGLE_IMAGE_FORMATS.includes(selectedFormat.value),
);

const optionalImageMax = computed<number | null>(() => {
    if (selectedFormat.value === null || carouselMaxImages.value !== null || isSingleImageFormat.value) {
        return null;
    }

    return OPTIONAL_IMAGE_MAX[selectedFormat.value] ?? DEFAULT_OPTIONAL_IMAGE_MAX;
});

/** Carousels start at two images; every other format may also ship none. */
const imageChoices = computed<number[]>(() => {
    if (carouselMaxImages.value !== null) {
        return Array.from({ length: carouselMaxImages.value - 1 }, (_, index) => index + 2);
    }

    if (optionalImageMax.value !== null) {
        return Array.from({ length: optionalImageMax.value + 1 }, (_, index) => index);
    }

    return [];
});

const submittedImageCount = computed(() =>
    isSingleImageFormat.value ? SINGLE_IMAGE_FORMAT_COUNT : imageCount.value,
);

const styleStepVisible = computed(
    () => selectedFormat.value !== null && formatBoundStyle.value === null && freeStyles.value.length > 0,
);

const styleAnswered = computed(() => resolvedStyle.value !== null);

/**
 * The image count is never blank — every format enters with the wizard's own
 * default already picked — so it is not a question the thread has to ask. It
 * rides along in the final block instead, next to the button that acts on it.
 */
const imageStepVisible = computed(() => styleAnswered.value && imageChoices.value.length > 0);

/**
 * A style with `needs_account` renders the post as that account's own card, so
 * the account is mandatory rather than merely offered — the server refuses a
 * generation without it. Otherwise the step only earns its place when there is
 * more than one account to choose between; a lone account is picked silently
 * in `selectFormat`.
 */
const styleNeedsAccount = computed(() => resolvedStyle.value?.needs_account ?? false);

const accountStepVisible = computed(
    () => styleAnswered.value && (accountsForFormat.value.length > 1 || styleNeedsAccount.value),
);

const selectedAccount = computed(
    () => accountsForFormat.value.find((account) => account.id === selectedAccountId.value) ?? null,
);

const choicesComplete = computed(
    () => selectedFormat.value !== null && styleAnswered.value && selectedAccount.value !== null,
);

const brandStepVisible = computed(
    () =>
        choicesComplete.value &&
        submittedImageCount.value > 0 &&
        (resolvedStyle.value?.applies_brand_visuals ?? false),
);

const isEmptyCatalog = computed(() => formatOptions.value.length === 0);

/**
 * A format was chosen but the catalog offers nothing to render it with, so
 * every later step stays hidden. Unreachable while the registry ships free
 * styles, but a card that answers a click with nothing at all is the worst
 * way to fail.
 */
const hasNoUsableStyle = computed(
    () => selectedFormat.value !== null && ! styleAnswered.value && ! styleStepVisible.value,
);

/**
 * Each step is one block in the thread, in the order it was asked: an open
 * question while it waits for an answer, and the answer itself — as the user's
 * own message — once it has one. The next question is appended below that
 * record rather than sprouting inside the same box, so the card reads top to
 * bottom like the rest of the conversation.
 *
 * That makes the sequence a strict alternation: nothing above an open question
 * is unanswered, and nothing below one is already answered. The image count
 * and the brand toggle are neither — both enter already answered by the
 * wizard's own default, so collapsing them into records would hide controls
 * the user never got to see, while leaving them open would strand a question
 * above choices that were already made. They live in the final block instead,
 * visible and adjustable next to the button that acts on them.
 */
const formatQuestionVisible = computed(
    () => selectedFormat.value === null || editingStep.value === 'format',
);

const formatChoiceVisible = computed(
    () => ! formatQuestionVisible.value && selectedFormatOption.value !== null,
);

/**
 * A step being changed is the last block in the thread: everything the old
 * answer had revealed is dropped until it is answered again, so the user never
 * edits a format while the steps that depend on it still stand below.
 */
const notEditing = computed(() => editingStep.value === null);

const styleBlockVisible = computed(() => styleStepVisible.value && editingStep.value !== 'format');

const styleQuestionVisible = computed(
    () => styleBlockVisible.value && (selectedStyleKey.value === null || editingStep.value === 'style'),
);

/**
 * Only a style the user actually picked reads back as their message — a style
 * bound to the format (the carousel's own) was never a choice, and the card
 * has never shown one.
 */
const styleChoiceVisible = computed(
    () => styleBlockVisible.value && ! styleQuestionVisible.value && resolvedStyle.value !== null,
);

const imagesBlockVisible = computed(() => imageStepVisible.value && notEditing.value);

const accountQuestionVisible = computed(
    () =>
        accountStepVisible.value &&
        (notEditing.value || editingStep.value === 'account') &&
        (selectedAccountId.value === null || editingStep.value === 'account'),
);

const accountChoiceVisible = computed(
    () => accountStepVisible.value && notEditing.value && selectedAccount.value !== null,
);

const brandBlockVisible = computed(() => brandStepVisible.value && notEditing.value);

const submitVisible = computed(() => choicesComplete.value && notEditing.value);

const formatChoiceLogos = computed(() =>
    selectedFormatOption.value === null ? [] : formatLogos(selectedFormatOption.value),
);

const accountChoiceLogos = computed(() => {
    const account = selectedAccount.value;

    if (account === null) {
        return [];
    }

    return [{ platform: account.platform, logo: getPlatformLogo(account.platform) }];
});

const reopen = (step: RecordedStep): void => {
    if (settled.value) {
        return;
    }

    editingStep.value = step;
};

const defaultImageCountFor = (format: string): number => {
    const carouselMax = CAROUSEL_MAX_IMAGES[format];

    if (carouselMax !== undefined) {
        return Math.min(CAROUSEL_DEFAULT_IMAGE_COUNT, carouselMax);
    }

    if (OPTIONAL_IMAGE_MAX[format] !== undefined) {
        return Math.min(DEFAULT_IMAGE_COUNT, OPTIONAL_IMAGE_MAX[format]);
    }

    return DEFAULT_IMAGE_COUNT;
};

const selectFormat = (option: FormatOption): void => {
    if (settled.value) {
        return;
    }

    editingStep.value = null;
    selectedFormat.value = option.value;
    selectedStyleKey.value = null;
    imageCount.value = defaultImageCountFor(option.value);
    selectedAccountId.value = option.accounts.length === 1 ? option.accounts[0].id : null;
};

/**
 * A workspace connected to one network has no format to choose between, so the
 * card picks it rather than charging the user a click that decides nothing and
 * hides every later step behind it. The step still renders, pre-selected: the
 * format determines everything below it, and a card opening straight onto
 * styles would never say where the post is going.
 */
watch(
    formatOptions,
    (options): void => {
        if (settled.value || selectedFormat.value !== null || options.length !== 1) {
            return;
        }

        selectFormat(options[0]);
    },
    { immediate: true },
);

const selectStyle = (key: string): void => {
    if (settled.value) {
        return;
    }

    editingStep.value = null;
    selectedStyleKey.value = key;
};

const selectImageCount = (count: number): void => {
    if (settled.value) {
        return;
    }

    imageCount.value = count;
};

const selectAccount = (id: string): void => {
    if (settled.value) {
        return;
    }

    editingStep.value = null;
    selectedAccountId.value = id;
};

/**
 * The line under an account's name in the account step. Two connections for
 * the same brand share a display name AND a logo (Instagram direct vs. through
 * a Facebook Page), so the handle is the only thing that tells them apart;
 * an account without one falls back to naming its platform.
 */
const accountHandle = (account: ChatPostGenerationAccount): string =>
    account.username ? `@${account.username}` : getPlatformLabel(account.platform);

/**
 * The chosen account, named so the sentence identifies exactly one connection
 * rather than merely a brand.
 */
const accountPhrase = computed<string>(() => {
    const account = selectedAccount.value;

    if (account === null) {
        return '';
    }

    return `${account.label} (${accountHandle(account)})`;
});

const imagesPhrase = computed<string>(() => {
    const count = submittedImageCount.value;

    if (count === 0) {
        return trans('chat.post_generation.sentence_images_none');
    }

    if (count === 1) {
        return trans('chat.post_generation.sentence_images_one');
    }

    return trans('chat.post_generation.sentence_images_other', { count: String(count) });
});

const brandPhrase = computed<string>(() =>
    trans(useBrandColors.value ? 'chat.post_generation.sentence_brand_on' : 'chat.post_generation.sentence_brand_off'),
);

/**
 * One readable sentence naming every choice, rather than an opaque token:
 * whoever reopens the conversation reads what was asked for, and the model
 * receives the choices alongside the prompt it already has from earlier in the
 * history. `generate_post` re-validates all of it server-side.
 */
const sentence = computed<string>(() => {
    const replacements: Record<string, string> = {
        format: formatLabel(selectedFormat.value ?? ''),
        style: resolvedStyle.value?.name ?? '',
        images: imagesPhrase.value,
        account: accountPhrase.value,
    };

    if (! brandStepVisible.value) {
        return trans('chat.post_generation.sentence', replacements);
    }

    return trans('chat.post_generation.sentence_with_brand', { ...replacements, brand: brandPhrase.value });
});

/**
 * What a settled card shows instead of its steps. The choices only exist in
 * this component, so a conversation reopened after the fact (`data.spent`)
 * has none to list — it says the choices were sent and leaves the detail to
 * the user message that carries the sentence, right below.
 */
const summaryParts = computed<string[]>(() => {
    if (selectedFormat.value === null) {
        return [];
    }

    const parts = [formatLabel(selectedFormat.value)];

    if (resolvedStyle.value !== null) {
        parts.push(resolvedStyle.value.name);
    }

    if (imageChoices.value.length > 0 || isSingleImageFormat.value) {
        parts.push(imagesPhrase.value);
    }

    if (selectedAccount.value !== null) {
        parts.push(accountPhrase.value);
    }

    if (brandStepVisible.value) {
        parts.push(brandPhrase.value);
    }

    return parts;
});

const canSubmit = computed(() => choicesComplete.value && ! settled.value && ! props.disabled);

const submit = (): void => {
    if (! canSubmit.value) {
        return;
    }

    const text = sentence.value;

    submitted.value = true;
    emit('submit', text);
};
</script>

<template>
    <div
        class="space-y-3"
        data-testid="chat-post-generation-card"
        dusk="chat-post-generation-card"
    >
        <ChatAssistantMessage
            v-if="isEmptyCatalog"
            :title="$t('chat.post_generation.unavailable')"
            data-testid="chat-post-generation-empty"
            dusk="chat-post-generation-empty"
        />

        <div
            v-else-if="settled"
            class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border border-foreground/15 bg-background px-3 py-2 text-xs text-muted-foreground"
            data-testid="chat-post-generation-sent"
            dusk="chat-post-generation-sent"
        >
            <IconCheck class="size-4 shrink-0" stroke-width="3" />

            <span class="font-semibold">{{ $t('chat.post_generation.sent') }}</span>

            <span
                v-for="(part, index) in summaryParts"
                :key="index"
                class="rounded-md bg-accent px-1.5 py-0.5 text-accent-foreground"
            >
                {{ part }}
            </span>
        </div>

        <template v-else>
            <ChatAssistantMessage
                v-if="formatQuestionVisible"
                :title="$t('chat.post_generation.format_question')"
                data-testid="chat-post-generation-format-step"
                dusk="chat-post-generation-format-step"
            >
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="option in formatOptions"
                        :key="option.value"
                        type="button"
                        class="flex cursor-pointer items-center gap-2 rounded-lg border border-foreground/15 bg-background p-2 text-left text-sm transition-colors hover:bg-foreground/5"
                        :class="{ 'border-foreground bg-accent hover:bg-accent': selectedFormat === option.value }"
                        :data-testid="`chat-post-generation-format-${option.value}`"
                        :dusk="`chat-post-generation-format-${option.value}`"
                        @click="selectFormat(option)"
                    >
                        <span class="flex -space-x-1.5">
                            <span
                                v-for="entry in formatLogos(option)"
                                :key="entry.platform"
                                class="inline-flex size-6 items-center justify-center overflow-hidden rounded-full border border-foreground/20 bg-card"
                            >
                                <img
                                    :src="entry.logo"
                                    :alt="getPlatformLabel(entry.platform)"
                                    class="size-full object-cover"
                                />
                            </span>
                        </span>

                        <span class="min-w-0 flex-1 truncate font-semibold text-foreground">
                            {{ formatLabel(option.value) }}
                        </span>

                        <IconCheck
                            v-if="selectedFormat === option.value"
                            class="size-4 shrink-0 text-foreground"
                            stroke-width="3"
                        />
                    </button>
                </div>
            </ChatAssistantMessage>

            <ChatPostGenerationChoice
                v-else-if="formatChoiceVisible && selectedFormatOption"
                :text="formatLabel(selectedFormatOption.value)"
                :logos="formatChoiceLogos"
                :changeable="formatOptions.length > 1"
                test-id="chat-post-generation-format-choice"
                @change="reopen('format')"
            />

            <ChatAssistantMessage
                v-if="hasNoUsableStyle"
                :title="$t('chat.post_generation.styles_unavailable')"
                data-testid="chat-post-generation-styles-unavailable"
                dusk="chat-post-generation-styles-unavailable"
            />

            <ChatAssistantMessage
                v-if="styleQuestionVisible"
                :title="$t('chat.post_generation.style_question')"
                data-testid="chat-post-generation-style-step"
                dusk="chat-post-generation-style-step"
            >
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="style in freeStyles"
                        :key="style.key"
                        type="button"
                        class="relative flex cursor-pointer flex-col overflow-hidden rounded-lg border border-foreground/15 bg-background text-left transition-colors hover:bg-foreground/5"
                        :class="{ 'border-foreground bg-accent hover:bg-accent': selectedStyleKey === style.key }"
                        :data-testid="`chat-post-generation-style-${style.key}`"
                        :dusk="`chat-post-generation-style-${style.key}`"
                        @click="selectStyle(style.key)"
                    >
                        <span class="aspect-video w-full overflow-hidden bg-muted">
                            <img
                                :src="style.preview"
                                :alt="style.name"
                                class="size-full object-cover"
                            />
                        </span>

                        <span class="min-w-0 flex-1 p-2">
                            <span class="block truncate text-sm font-semibold text-foreground">{{ style.name }}</span>
                            <span class="block text-xs text-muted-foreground">{{ style.description }}</span>
                        </span>

                        <IconCheck
                            v-if="selectedStyleKey === style.key"
                            class="absolute right-2 top-2 size-5 rounded-full bg-card p-0.5 text-foreground"
                            stroke-width="3"
                        />
                    </button>
                </div>
            </ChatAssistantMessage>

            <ChatPostGenerationChoice
                v-else-if="styleChoiceVisible && resolvedStyle"
                :text="resolvedStyle.name"
                changeable
                test-id="chat-post-generation-style-choice"
                @change="reopen('style')"
            />

            <ChatAssistantMessage
                v-if="accountQuestionVisible"
                :title="$t('chat.post_generation.account_question')"
                data-testid="chat-post-generation-account-step"
                dusk="chat-post-generation-account-step"
            >
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="account in accountsForFormat"
                        :key="account.id"
                        type="button"
                        class="flex cursor-pointer items-center gap-2 rounded-lg border border-foreground/15 bg-background p-2 text-left text-sm transition-colors hover:bg-foreground/5"
                        :class="{ 'border-foreground bg-accent hover:bg-accent': selectedAccountId === account.id }"
                        :data-testid="`chat-post-generation-account-${account.id}`"
                        :dusk="`chat-post-generation-account-${account.id}`"
                        @click="selectAccount(account.id)"
                    >
                        <span class="inline-flex size-6 shrink-0 items-center justify-center overflow-hidden rounded-full border border-foreground/20 bg-card">
                            <img
                                :src="getPlatformLogo(account.platform)"
                                :alt="getPlatformLabel(account.platform)"
                                class="size-full object-cover"
                            />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-foreground">{{ account.label }}</span>
                            <span class="block truncate text-xs text-muted-foreground">{{ accountHandle(account) }}</span>
                        </span>

                        <IconCheck
                            v-if="selectedAccountId === account.id"
                            class="size-4 shrink-0 text-foreground"
                            stroke-width="3"
                        />
                    </button>
                </div>
            </ChatAssistantMessage>

            <ChatPostGenerationChoice
                v-else-if="accountChoiceVisible"
                :text="accountPhrase"
                :logos="accountChoiceLogos"
                changeable
                test-id="chat-post-generation-account-choice"
                @change="reopen('account')"
            />

            <div
                v-if="submitVisible"
                class="flex animate-in flex-col gap-3 rounded-xl border-2 border-foreground bg-card p-3 shadow-2xs fade-in slide-in-from-bottom-2 duration-300 motion-reduce:animate-none"
                data-testid="chat-post-generation-final"
                dusk="chat-post-generation-final"
            >
                <div
                    v-if="imagesBlockVisible"
                    class="space-y-2"
                    data-testid="chat-post-generation-images-step"
                    dusk="chat-post-generation-images-step"
                >
                    <p class="text-sm font-bold tracking-tight text-foreground">
                        {{ $t('chat.post_generation.images_question') }}
                    </p>

                    <div class="flex flex-wrap gap-1.5">
                        <Button
                            v-for="count in imageChoices"
                            :key="count"
                            type="button"
                            size="sm"
                            :variant="imageCount === count ? 'default' : 'outline'"
                            :data-testid="`chat-post-generation-images-${count}`"
                            :dusk="`chat-post-generation-images-${count}`"
                            @click="selectImageCount(count)"
                        >
                            {{ count === 0 ? $t('chat.post_generation.images_none') : count }}
                        </Button>
                    </div>
                </div>

                <div
                    v-if="brandBlockVisible"
                    class="flex items-center justify-between gap-3 border-t border-foreground/15 pt-3"
                    data-testid="chat-post-generation-brand-step"
                    dusk="chat-post-generation-brand-step"
                >
                    <div class="min-w-0 space-y-0.5">
                        <Label :for="brandColorsId" class="text-sm font-semibold">
                            {{ $t('chat.post_generation.brand_colors_label') }}
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            {{ $t('chat.post_generation.brand_colors_description') }}
                        </p>
                    </div>

                    <Switch
                        :id="brandColorsId"
                        v-model="useBrandColors"
                        data-testid="chat-post-generation-brand-toggle"
                        dusk="chat-post-generation-brand-toggle"
                    />
                </div>

                <div class="flex justify-end">
                    <Button
                        type="button"
                        size="sm"
                        :disabled="! canSubmit"
                        data-testid="chat-post-generation-submit"
                        dusk="chat-post-generation-submit"
                        @click="submit"
                    >
                        <IconSparkles class="size-4" />
                        {{ $t('chat.post_generation.submit') }}
                    </Button>
                </div>
            </div>

        </template>
    </div>
</template>
