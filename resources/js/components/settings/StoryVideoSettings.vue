<script setup lang="ts">
// PATCH:story-motion-settings: the workspace's Story video look (Settings, Brand). Saved on its own, so the
// brand form above stays untouched.
import { useForm } from '@inertiajs/vue3';

import WorkspaceController from '@/actions/App/Http/Controllers/App/WorkspaceController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import type {
    StoryMotionMode,
    StoryMotionSettings,
} from '@/types/story-motion';

const props = defineProps<{
    settings: StoryMotionSettings;
    fonts: string[];
    effects: string[];
    modes: StoryMotionMode[];
    hasLogo: boolean;
}>();

// Display names of the fonts shipped in the render service; the names are brand names and stay untranslated.
const fontNames: Record<string, string> = {
    inter: 'Inter',
    commissioner: 'Commissioner',
    comfortaa: 'Comfortaa',
    'noto-sans': 'Noto Sans',
    'noto-serif': 'Noto Serif',
};

const form = useForm<StoryMotionSettings>({
    enabled: props.settings.enabled,
    font: props.settings.font,
    effects: [...props.settings.effects],
    mode: props.settings.mode,
    logo_layer: props.settings.logo_layer,
    ai_notice: { ...props.settings.ai_notice },
    ending: { ...props.settings.ending },
});

const pillClass = (active: boolean) =>
    `cursor-pointer rounded-full border-2 px-3 py-1 text-xs font-bold uppercase tracking-widest transition-colors ${
        active
            ? 'border-foreground bg-violet-100 text-foreground shadow-2xs'
            : 'border-foreground/30 text-foreground/70 hover:border-foreground hover:text-foreground'
    }`;

// With "one effect" exactly one is selected, with the other modes any number (at least one).
const pickEffect = (effect: string) => {
    if (form.mode === 'fixed') {
        form.effects = [effect];

        return;
    }

    const selected = form.effects.includes(effect);
    if (selected && form.effects.length === 1) {
        return;
    }
    form.effects = selected
        ? form.effects.filter((entry) => entry !== effect)
        : [...form.effects, effect];
};

const pickMode = (mode: StoryMotionMode) => {
    form.mode = mode;
    if (mode === 'fixed' && form.effects.length > 1) {
        // The same canonical order the server uses: the first selected effect of the list below stays.
        form.effects = [
            props.effects.find((effect) => form.effects.includes(effect)) ??
                form.effects[0],
        ];
    }
};

const submit = () => {
    form.put(WorkspaceController.updateStoryVideo.url(), {
        preserveScroll: true,
    });
};
</script>

<template>
    <form
        class="flex flex-col space-y-6"
        data-testid="story-video-settings"
        @submit.prevent="submit"
    >
        <HeadingSmall
            :title="$t('settings.story_video.title')"
            :description="$t('settings.story_video.description')"
        />

        <div
            class="flex items-center justify-between gap-4 rounded-xl border-2 border-foreground bg-card p-4 shadow-2xs"
        >
            <div class="space-y-0.5">
                <Label for="story-video-enabled" class="text-sm font-bold">{{
                    $t('settings.story_video.enabled')
                }}</Label>
                <p class="text-sm text-foreground/70">
                    {{ $t('settings.story_video.enabled_description') }}
                </p>
            </div>
            <Switch
                id="story-video-enabled"
                v-model="form.enabled"
                data-testid="story-video-enabled"
            />
        </div>
        <InputError :message="form.errors.enabled" />

        <template v-if="form.enabled">
            <div class="grid gap-2">
                <Label>{{ $t('settings.story_video.font') }}</Label>
                <p class="text-xs font-medium text-foreground/60">
                    {{ $t('settings.story_video.font_description') }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="font in fonts"
                        :key="font"
                        type="button"
                        :class="pillClass(form.font === font)"
                        :aria-pressed="form.font === font"
                        :data-testid="`story-video-font-${font}`"
                        @click="form.font = font"
                    >
                        {{ fontNames[font] ?? font }}
                    </button>
                </div>
                <InputError :message="form.errors.font" />
            </div>

            <div class="grid gap-2">
                <Label>{{ $t('settings.story_video.mode') }}</Label>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="mode in modes"
                        :key="mode"
                        type="button"
                        :class="pillClass(form.mode === mode)"
                        :aria-pressed="form.mode === mode"
                        :data-testid="`story-video-mode-${mode}`"
                        @click="pickMode(mode)"
                    >
                        {{ $t(`settings.story_video.mode_option.${mode}`) }}
                    </button>
                </div>
                <p class="text-xs font-medium text-foreground/60">
                    {{
                        $t(`settings.story_video.mode_description.${form.mode}`)
                    }}
                </p>
                <InputError :message="form.errors.mode" />
            </div>

            <div class="grid gap-2">
                <Label>{{ $t('settings.story_video.effects') }}</Label>
                <p class="text-xs font-medium text-foreground/60">
                    {{ $t('settings.story_video.effects_description') }}
                </p>
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="effect in effects"
                        :key="effect"
                        type="button"
                        :aria-pressed="form.effects.includes(effect)"
                        :class="[
                            'flex flex-col gap-1 rounded-xl border-2 p-3 text-left transition-colors',
                            form.effects.includes(effect)
                                ? 'border-foreground bg-violet-100 shadow-2xs'
                                : 'border-foreground/30 hover:border-foreground',
                        ]"
                        :data-testid="`story-video-effect-${effect}`"
                        @click="pickEffect(effect)"
                    >
                        <span class="text-sm font-bold text-foreground">{{
                            $t(`settings.story_video.effect.${effect}`)
                        }}</span>
                        <span class="text-xs font-medium text-foreground/70">{{
                            $t(
                                `settings.story_video.effect_description.${effect}`,
                            )
                        }}</span>
                    </button>
                </div>
                <InputError :message="form.errors.effects" />
            </div>

            <div class="grid gap-3">
                <div
                    class="flex items-center justify-between gap-4 rounded-xl border-2 border-foreground bg-card p-4 shadow-2xs"
                >
                    <div class="space-y-0.5">
                        <Label
                            for="story-video-logo"
                            class="text-sm font-bold"
                            >{{ $t('settings.story_video.logo_layer') }}</Label
                        >
                        <p class="text-sm text-foreground/70">
                            {{
                                $t(
                                    'settings.story_video.logo_layer_description',
                                )
                            }}
                        </p>
                    </div>
                    <Switch
                        id="story-video-logo"
                        v-model="form.logo_layer"
                        data-testid="story-video-logo"
                    />
                </div>
                <p
                    v-if="form.logo_layer && !hasLogo"
                    class="text-sm font-medium text-amber-700 dark:text-amber-500"
                    data-testid="story-video-logo-missing"
                >
                    {{ $t('settings.story_video.logo_missing') }}
                </p>
                <InputError :message="form.errors.logo_layer" />
            </div>

            <div
                class="grid gap-3 rounded-xl border-2 border-foreground bg-card p-4 shadow-2xs"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-0.5">
                        <Label
                            for="story-video-notice"
                            class="text-sm font-bold"
                            >{{ $t('settings.story_video.ai_notice') }}</Label
                        >
                        <p class="text-sm text-foreground/70">
                            {{
                                $t('settings.story_video.ai_notice_description')
                            }}
                        </p>
                    </div>
                    <Switch
                        id="story-video-notice"
                        v-model="form.ai_notice.enabled"
                        data-testid="story-video-notice"
                    />
                </div>
                <div v-if="form.ai_notice.enabled" class="grid gap-2">
                    <Label for="story-video-notice-text">{{
                        $t('settings.story_video.ai_notice_text')
                    }}</Label>
                    <Input
                        id="story-video-notice-text"
                        v-model="form.ai_notice.text"
                        type="text"
                        :placeholder="
                            $t('settings.story_video.ai_notice_placeholder')
                        "
                        data-testid="story-video-notice-text"
                    />
                    <InputError :message="form.errors['ai_notice.text']" />
                </div>
            </div>

            <div
                class="grid gap-3 rounded-xl border-2 border-foreground bg-card p-4 shadow-2xs"
            >
                <div class="flex items-center justify-between gap-4">
                    <div class="space-y-0.5">
                        <Label
                            for="story-video-ending"
                            class="text-sm font-bold"
                            >{{ $t('settings.story_video.ending') }}</Label
                        >
                        <p class="text-sm text-foreground/70">
                            {{ $t('settings.story_video.ending_description') }}
                        </p>
                    </div>
                    <Switch
                        id="story-video-ending"
                        v-model="form.ending.enabled"
                        data-testid="story-video-ending"
                    />
                </div>
                <div
                    v-if="form.ending.enabled"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div class="grid gap-2">
                        <Label for="story-video-ending-name">{{
                            $t('settings.story_video.ending_name')
                        }}</Label>
                        <Input
                            id="story-video-ending-name"
                            v-model="form.ending.name"
                            type="text"
                            :placeholder="
                                $t(
                                    'settings.story_video.ending_name_placeholder',
                                )
                            "
                            data-testid="story-video-ending-name"
                        />
                        <InputError :message="form.errors['ending.name']" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="story-video-ending-line">{{
                            $t('settings.story_video.ending_line')
                        }}</Label>
                        <Input
                            id="story-video-ending-line"
                            v-model="form.ending.line"
                            type="text"
                            :placeholder="
                                $t(
                                    'settings.story_video.ending_line_placeholder',
                                )
                            "
                            data-testid="story-video-ending-line"
                        />
                        <InputError :message="form.errors['ending.line']" />
                    </div>
                </div>
            </div>

            <p class="text-xs font-medium text-foreground/60">
                {{ $t('settings.story_video.bold_hint') }}
            </p>
        </template>

        <Button
            :disabled="form.processing"
            class="self-start"
            data-testid="story-video-save"
            >{{ $t('settings.workspace.save') }}</Button
        >
    </form>
</template>
