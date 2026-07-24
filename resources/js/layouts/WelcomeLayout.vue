<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { home } from '@/routes/app';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        step: number;
        totalSteps?: number;
        wide?: boolean;
    }>(),
    {
        title: undefined,
        description: undefined,
        totalSteps: 4,
        wide: false,
    },
);
</script>

<template>
    <div
        class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
    >
        <div class="w-full" :class="wide ? 'max-w-4xl' : 'max-w-xl'">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <Link
                        :href="home()"
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

                    <div class="flex items-center gap-2">
                        <div
                            v-for="stepNumber in totalSteps"
                            :key="stepNumber"
                            class="h-2 w-8 rounded-full transition-colors"
                            :class="
                                stepNumber <= step ? 'bg-primary' : 'bg-muted'
                            "
                        />
                    </div>

                    <div class="space-y-2 text-center">
                        <h1 class="text-2xl font-bold">{{ title }}</h1>
                        <p class="text-muted-foreground">
                            {{ description }}
                        </p>
                    </div>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
