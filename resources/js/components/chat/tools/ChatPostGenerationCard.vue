<script setup lang="ts">
import { IconCheck, IconPencil, IconSparkles } from '@tabler/icons-vue';
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
    ChatPostGenerationCopy,
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
        /** The turn this card sent failed, so its choices were never delivered. */
        failed?: boolean;
    }>(),
    { disabled: false, failed: false },
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
    /** Already translated by the server, in the conversation's language. */
    label: string;
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

/**
 * The topic becomes `generate_post`'s `prompt`, so the card refuses to submit
 * what the server would reject. Mirrors `App\Support\AiPromptRules`
 * (PROMPT_MIN_LENGTH / PROMPT_MAX_LENGTH) — keep the two in step.
 */
const TOPIC_MIN_LENGTH = 3;

const brandColorsId = useId();

/**
 * One line of the card, in the language of the conversation.
 *
 * The assistant replies in whatever language the user writes in, while the
 * interface follows the user's app setting — so every string the card shows
 * arrives already resolved in `data.copy`, and nothing here goes through the
 * app's own locale bindings. The `trans()` fallback covers a payload stored
 * before the card worked this way; it renders in the app locale, which is the
 * old behaviour rather than a blank card.
 */
const line = (key: keyof ChatPostGenerationCopy): string =>
    props.data?.copy?.[key] ?? trans(`chat.post_generation.${key}`);

/**
 * Fill a line's `:placeholders` from values only the client has. One pass, so
 * a replacement whose own text contains a colon-word (a topic like "re: the
 * launch") is never substituted into a second time.
 */
const fill = (template: string, replacements: Record<string, string>): string =>
    template.replace(/:(\w+)/g, (match, name: string) => replacements[name] ?? match);

const selectedFormat = ref<string | null>(null);
const selectedStyleKey = ref<string | null>(null);
const imageCount = ref(DEFAULT_IMAGE_COUNT);
const selectedAccountId = ref<string | null>(null);
const submitted = ref(false);

/**
 * A failed turn hands the card back. `submit()` latches optimistically because
 * nothing calls back on success, which is fine while the message lands — but a
 * request that fails leaves the card collapsed into "Choices sent." beside a
 * banner offering a retry the user has no way to take, since the card was the
 * only place the choices existed. Un-latching is not a second submit: it
 * restores the buttons and waits for the user to press Generate again.
 */
watch(
    () => props.failed,
    (failed): void => {
        if (failed) {
            submitted.value = false;
        }
    },
);

/**
 * Whether the account came from the user or from the card. `selectFormat`
 * picks a format's only account silently, and outside self-hosted that is
 * every format there is (`App\Observers\SocialAccountObserver::creating`
 * allows one account per network per workspace, and no content type spans two
 * networks). A step nobody was asked about must not read back as the user's
 * own message, so this is what separates an answer from an assumption.
 */
const accountAnswered = ref(false);

/**
 * Null until the user edits the field, so the model's own extraction stays
 * live: `data` is re-parsed on every parent render and replaced outright when
 * `ToolReplayer` re-runs the tool on reopen, and a value snapshotted at setup
 * would silently outlive the payload it came from — same reason
 * `brandColorsOverride` is null-until-touched.
 */


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

/**
 * What the post should be about. The card always asks, pre-filled with
 * whatever the model extracted from the conversation: "post about the X
 * launch" carries a topic and "make me a post" does not, and only the user can
 * tell the difference between a topic they meant and one that was inferred.
 */
const topicValue = computed<string>(() => (props.data?.topic ?? '').trim());



/**
 * The tool refuses to open a card without a topic, so one is always present.
 * The bound is kept as the card's own guard: a payload that somehow arrives
 * without one must not reach `generate_post`, which would reject it anyway.
 */
const topicUsable = computed(() => topicValue.value.length >= TOPIC_MIN_LENGTH);

const styles = computed<ChatPostGenerationStyle[]>(() => props.data?.styles ?? []);

const formatOptions = computed<FormatOption[]>(() => {
    const options = new Map<string, FormatOption>();

    for (const entry of props.data?.formats ?? []) {
        const option: FormatOption = options.get(entry.value) ?? {
            value: entry.value,
            label: entry.label ?? entry.value,
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

/**
 * The account is the last thing asked, so it waits on the style — both because
 * a style that needs an account decides whether it is asked at all, and
 * because a card must never hold two open questions at once. Without the
 * `styleAnswered` gate the account question appears beneath the style question
 * the user has not answered yet, and answering it leaves a record sitting
 * under an open question. It also un-asks itself when the style is reopened,
 * which is what keeps everything below a reopened step out of the way.
 */
const accountStepVisible = computed(
    () =>
        styleAnswered.value &&
        topicUsable.value &&
        (accountsForFormat.value.length > 1 || styleNeedsAccount.value),
);

const selectedAccount = computed(
    () => accountsForFormat.value.find((account) => account.id === selectedAccountId.value) ?? null,
);

const choicesComplete = computed(
    () =>
        selectedFormat.value !== null &&
        styleAnswered.value &&
        topicUsable.value &&
        selectedAccount.value !== null,
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
const formatQuestionVisible = computed(() => selectedFormat.value === null);

const formatChoiceVisible = computed(() => selectedFormatOption.value !== null);

const styleQuestionVisible = computed(
    () => styleStepVisible.value && selectedStyleKey.value === null,
);

/**
 * Only a style the user actually picked reads back as their message — a style
 * bound to the format (the carousel's own) was never a choice, and the card
 * has never shown one.
 */
const styleChoiceVisible = computed(
    () => styleStepVisible.value && selectedStyleKey.value !== null && resolvedStyle.value !== null,
);

const accountQuestionVisible = computed(
    () => accountStepVisible.value && selectedAccountId.value === null,
);

const accountChoiceVisible = computed(
    () => accountStepVisible.value && accountAnswered.value && selectedAccount.value !== null,
);

/**
 * The account the card picked on the user's behalf. It still has to be said —
 * a `needs_account` style renders the post AS that account's card — but it
 * belongs with the other decisions the user never made, in the final block,
 * not as a record of something they said.
 */
const autoAccountVisible = computed(
    () => selectedAccount.value !== null && ! accountAnswered.value,
);

const submitVisible = computed(() => choicesComplete.value);

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

/**
 * Reopening a step un-answers it, rather than flagging it as "being edited".
 * The question is then live again purely because it has no answer, and every
 * step below it disappears on its own, because they all read from the answers
 * above them. An editing flag lets a second edit strand the first: reopening
 * the account and then changing the style used to bring the old account back
 * as an answered record and silently drop the question the user had opened.
 *
 * Only the reopened step is cleared. The format is the exception — the style
 * and the account list both come from it, so they cannot outlive it.
 */
const reopen = (step: RecordedStep): void => {
    if (settled.value) {
        return;
    }

    if (step === 'style') {
        selectedStyleKey.value = null;

        return;
    }

    if (step === 'format') {
        selectedFormat.value = null;
        selectedStyleKey.value = null;
    }

    selectedAccountId.value = null;
    accountAnswered.value = false;
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

    selectedFormat.value = option.value;
    selectedStyleKey.value = null;
    imageCount.value = defaultImageCountFor(option.value);
    selectedAccountId.value = option.accounts.length === 1 ? option.accounts[0].id : null;
    accountAnswered.value = false;
};

/**
 * Whether the card has already applied a format nobody clicked. Reopening the
 * format un-selects it, and `formatOptions` is rebuilt whenever the payload is
 * re-parsed — without this, that rebuild would re-apply the same format and
 * close the question the user had just opened.
 */
const formatApplied = ref(false);

/**
 * The two formats the user never has to click.
 *
 * A format they already named — "quero gerar um carrousel de instagram" —
 * arrives on the payload, read from the conversation by the model, and asking
 * again would ignore what they just said. And a workspace connected to one
 * network has no format to choose between at all.
 *
 * Both are recorded rather than pre-selected in the question: the format comes
 * from a closed list, so a wrong one is legible in the record and undone with
 * its Change link — which is exactly why the topic, free text that a skimming
 * user would confirm unread, is still asked instead.
 */
watch(
    formatOptions,
    (options): void => {
        if (settled.value || formatApplied.value || selectedFormat.value !== null) {
            return;
        }

        const named = options.find((option) => option.value === props.data?.format);
        const only = options.length === 1 ? options[0] : undefined;
        const applied = named ?? only;

        if (applied === undefined) {
            return;
        }

        formatApplied.value = true;
        selectFormat(applied);
    },
    { immediate: true },
);

const selectStyle = (key: string): void => {
    if (settled.value) {
        return;
    }

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

    selectedAccountId.value = id;
    accountAnswered.value = true;
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
        return line('sentence_images_none');
    }

    if (count === 1) {
        return line('sentence_images_one');
    }

    return fill(line('sentence_images_other'), { count: String(count) });
});

const brandPhrase = computed<string>(() =>
    line(useBrandColors.value ? 'sentence_brand_on' : 'sentence_brand_off'),
);

/**
 * One readable sentence naming every choice, rather than an opaque token:
 * whoever reopens the conversation reads what was asked for, and the model
 * receives the choices alongside the prompt it already has from earlier in the
 * history. `generate_post` re-validates all of it server-side.
 */
const sentence = computed<string>(() => {
    const replacements: Record<string, string> = {
        format: selectedFormatOption.value?.label ?? '',
        topic: topicValue.value,
        style: resolvedStyle.value?.name ?? '',
        images: imagesPhrase.value,
        account: accountPhrase.value,
    };

    if (! brandStepVisible.value) {
        return fill(line('sentence'), replacements);
    }

    return fill(line('sentence_with_brand'), { ...replacements, brand: brandPhrase.value });
});

/**
 * What a settled card shows instead of its steps. The choices only exist in
 * this component, so a conversation reopened after the fact (`data.spent`)
 * has none to list — it says the choices were sent and leaves the detail to
 * the user message that carries the sentence, right below.
 */
const summaryParts = computed<string[]>(() => {
    if (selectedFormatOption.value === null) {
        return [];
    }

    const parts = [selectedFormatOption.value.label];

    if (resolvedStyle.value !== null) {
        parts.push(resolvedStyle.value.name);
    }

    if (topicUsable.value) {
        parts.push(topicValue.value);
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
            :title="line('unavailable')"
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

            <span class="font-semibold">{{ line('sent') }}</span>

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
                :title="line('format_question')"
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
                            {{ option.label }}
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
                :text="selectedFormatOption.label"
                :logos="formatChoiceLogos"
                :changeable="formatOptions.length > 1"
                :change-label="line('change')"
                test-id="chat-post-generation-format-choice"
                @change="reopen('format')"
            />

            <ChatAssistantMessage
                v-if="hasNoUsableStyle"
                :title="line('styles_unavailable')"
                data-testid="chat-post-generation-styles-unavailable"
                dusk="chat-post-generation-styles-unavailable"
            />

            <ChatAssistantMessage
                v-if="styleQuestionVisible"
                :title="line('style_question')"
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
                :change-label="line('change')"
                test-id="chat-post-generation-style-choice"
                @change="reopen('style')"
            />

            <ChatAssistantMessage
                v-if="accountQuestionVisible"
                :title="line('account_question')"
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
                :changeable="accountsForFormat.length > 1"
                :change-label="line('change')"
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
                    v-if="topicUsable"
                    class="flex items-start gap-2 text-sm text-muted-foreground"
                    data-testid="chat-post-generation-topic-line"
                    dusk="chat-post-generation-topic-line"
                >
                    <IconPencil class="mt-0.5 size-4 shrink-0" />

                    <span class="min-w-0 flex-1">
                        {{ fill(line('topic_line'), { topic: topicValue }) }}
                    </span>
                </div>

                <div
                    v-if="autoAccountVisible"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                    data-testid="chat-post-generation-account-auto"
                    dusk="chat-post-generation-account-auto"
                >
                    <span
                        v-for="entry in accountChoiceLogos"
                        :key="entry.platform"
                        class="inline-flex size-5 shrink-0 items-center justify-center overflow-hidden rounded-full border border-foreground/20 bg-card"
                    >
                        <img
                            :src="entry.logo"
                            :alt="getPlatformLabel(entry.platform)"
                            class="size-full object-cover"
                        />
                    </span>

                    <span class="min-w-0 flex-1 truncate">
                        {{ fill(line('posting_to'), { account: accountPhrase }) }}
                    </span>
                </div>

                <div
                    v-if="imageStepVisible"
                    class="space-y-2"
                    data-testid="chat-post-generation-images-step"
                    dusk="chat-post-generation-images-step"
                >
                    <p class="text-sm font-bold tracking-tight text-foreground">
                        {{ line('images_question') }}
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
                            {{ count === 0 ? line('images_none') : count }}
                        </Button>
                    </div>
                </div>

                <div
                    v-if="brandStepVisible"
                    class="flex items-center justify-between gap-3 border-t border-foreground/15 pt-3"
                    data-testid="chat-post-generation-brand-step"
                    dusk="chat-post-generation-brand-step"
                >
                    <div class="min-w-0 space-y-0.5">
                        <Label :for="brandColorsId" class="text-sm font-semibold">
                            {{ line('brand_colors_label') }}
                        </Label>
                        <p class="text-xs text-muted-foreground">
                            {{ line('brand_colors_description') }}
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
                        {{ line('submit') }}
                    </Button>
                </div>
            </div>

        </template>
    </div>
</template>
