<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import Toast from '@/components/Toast.vue';
import { welcome } from '@/routes/app';

const maxWidthClass = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
    '3xl': 'max-w-3xl',
    '4xl': 'max-w-4xl',
    '5xl': 'max-w-5xl',
    '6xl': 'max-w-6xl',
    '7xl': 'max-w-7xl',
} as const;

type MaxWidthSize = keyof typeof maxWidthClass;

const props = withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        step?: number;
        totalSteps?: number;
        size?: MaxWidthSize;
        chat?: boolean;
    }>(),
    {
        title: undefined,
        description: undefined,
        step: undefined,
        totalSteps: 4,
        size: 'xl',
        chat: false,
    },
);

const emit = defineEmits<{
    selectStep: [step: number];
}>();

const canNavigateTo = (stepNumber: number): boolean =>
    props.step !== undefined && stepNumber < props.step;
</script>

<template>
    <div
        :class="[
            'flex min-h-svh flex-col items-center bg-background',
            chat
                ? 'justify-start'
                : 'justify-center gap-6 p-6 md:p-10',
        ]"
    >
        <div
            class="w-full"
            :class="[
                chat ? 'flex min-h-svh flex-col' : maxWidthClass[size],
            ]"
        >
            <div
                :class="
                    chat
                        ? 'flex min-h-svh flex-col'
                        : 'flex flex-col gap-8'
                "
            >
                <div
                    v-if="!chat"
                    class="flex flex-col items-center gap-4"
                >
                    <Link
                        :href="welcome()"
                        class="flex flex-col items-center gap-2 font-medium"
                    >
                        <img
                            src="/images/trypost/logo-light.png"
                            alt="TryPost"
                            class="h-8 w-auto dark:hidden"
                        />
                        <img
                            src="/images/trypost/logo-dark.png"
                            alt="TryPost"
                            class="hidden h-8 w-auto dark:block"
                        />
                    </Link>

                    <nav
                        v-if="step !== undefined"
                        class="flex items-center gap-2"
                        :aria-label="$t('welcome.progress')"
                    >
                        <template
                            v-for="stepNumber in totalSteps"
                            :key="stepNumber"
                        >
                            <button
                                v-if="canNavigateTo(stepNumber)"
                                type="button"
                                class="flex h-6 w-8 items-center"
                                :aria-label="
                                    $t('welcome.go_to_step', {
                                        step: String(stepNumber),
                                    })
                                "
                                :data-testid="`welcome-step-${stepNumber}`"
                                :dusk="`welcome-step-${stepNumber}`"
                                @click="emit('selectStep', stepNumber)"
                            >
                                <span
                                    class="h-2 w-full rounded-full bg-primary transition-opacity hover:opacity-70 motion-reduce:transition-none"
                                />
                            </button>
                            <div
                                v-else
                                class="flex h-6 w-8 items-center"
                                :data-testid="`welcome-step-${stepNumber}`"
                                :dusk="`welcome-step-${stepNumber}`"
                                :aria-current="
                                    stepNumber === step ? 'step' : undefined
                                "
                                :aria-label="
                                    stepNumber === step
                                        ? $t('welcome.step_current', {
                                              step: String(stepNumber),
                                          })
                                        : undefined
                                "
                            >
                                <span
                                    :class="[
                                        'h-2 w-full rounded-full transition-colors',
                                        stepNumber <= step
                                            ? 'bg-primary'
                                            : 'bg-muted',
                                    ]"
                                />
                            </div>
                        </template>
                    </nav>

                    <div v-if="title" class="space-y-2 text-center">
                        <h1 class="text-2xl font-bold">{{ title }}</h1>
                        <p v-if="description" class="text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <div
                    :class="
                        chat
                            ? 'flex flex-1 flex-col px-4 md:px-6'
                            : undefined
                    "
                >
                    <slot />
                </div>
            </div>
        </div>
        <Toast />
    </div>
</template>
