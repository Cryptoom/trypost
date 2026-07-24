<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconArrowRight,
    IconBriefcase,
    IconBuildingSkyscraper,
    IconBuildingStore,
    IconCheck,
    IconCode,
    IconDots,
    IconRocket,
    IconShoppingBag,
    IconSpeakerphone,
    IconUser,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { FunctionalComponent } from 'vue';

import { Button } from '@/components/ui/button';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/persona';

const props = defineProps<{
    personas: string[];
    selected?: string | null;
}>();

const form = useForm({ persona: props.selected ?? '' });

const personaMeta: Record<
    string,
    { icon: FunctionalComponent; color: string }
> = {
    creator: { icon: IconUser, color: 'text-rose-600' },
    freelancer: { icon: IconBriefcase, color: 'text-amber-600' },
    developer: { icon: IconCode, color: 'text-cyan-600' },
    startup: { icon: IconRocket, color: 'text-violet-700' },
    agency: { icon: IconBuildingSkyscraper, color: 'text-blue-700' },
    small_business: { icon: IconBuildingStore, color: 'text-emerald-600' },
    marketer: { icon: IconSpeakerphone, color: 'text-fuchsia-600' },
    online_store: { icon: IconShoppingBag, color: 'text-teal-600' },
    other: { icon: IconDots, color: 'text-sky-600' },
};

const personaIcon = (value: string): FunctionalComponent =>
    personaMeta[value]?.icon ?? IconDots;

const personaColor = (value: string): string =>
    personaMeta[value]?.color ?? 'text-foreground';

const personaLabel = (value: string): string =>
    trans(`welcome.personas.${value}`);

const select = (value: string): void => {
    form.persona = value;
};

const submit = (): void => {
    if (!form.persona || form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.title')" />

    <WelcomeLayout
        :title="$t('welcome.title')"
        :description="$t('welcome.description')"
        :step="1"
        wide
    >
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <button
                v-for="persona in personas"
                :key="persona"
                type="button"
                :class="[
                    'relative flex cursor-pointer flex-col items-start gap-3 rounded-2xl border-2 border-foreground p-5 text-left shadow-2xs transition-shadow hover:shadow-md',
                    form.persona === persona ? 'bg-violet-100' : 'bg-card',
                ]"
                @click="select(persona)"
            >
                <span
                    class="inline-flex size-10 items-center justify-center rounded-2xl border-2 border-foreground bg-card shadow-2xs"
                >
                    <component
                        :is="personaIcon(persona)"
                        :class="[personaColor(persona), 'size-5']"
                        stroke-width="2.25"
                    />
                </span>
                <span
                    class="text-base font-bold tracking-tight text-foreground"
                >
                    {{ personaLabel(persona) }}
                </span>
                <span
                    v-if="form.persona === persona"
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
                :disabled="!form.persona || form.processing"
                @click="submit"
            >
                {{ $t('welcome.continue') }}
                <IconArrowRight class="size-4" />
            </Button>
        </div>
    </WelcomeLayout>
</template>
