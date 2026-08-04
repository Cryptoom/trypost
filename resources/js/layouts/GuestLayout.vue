<script setup lang="ts">
import dayjs from '@/dayjs';

defineProps<{
    title?: string;
}>();

const currentYear = dayjs().year();
</script>

<template>
    <div class="min-h-svh bg-background">
        <!-- Header -->
        <header class="border-b">
            <div
                class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4"
            >
                <div class="flex items-center gap-2">
                    <img
                        src="/images/trypost/logo-light.png"
                        alt="TryPost"
                        class="h-7 w-auto dark:hidden"
                    />
                    <img
                        src="/images/trypost/logo-dark.png"
                        alt="TryPost"
                        class="hidden h-7 w-auto dark:block"
                    />
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="mx-auto max-w-5xl px-6 py-12">
            <h1 v-if="title" class="mb-8 text-3xl font-bold">{{ title }}</h1>
            <slot />
        </main>

        <!-- Footer -->
        <footer class="mt-auto border-t">
            <div class="mx-auto max-w-5xl px-6 py-8">
                <p class="text-center text-sm text-muted-foreground">
                    &copy; {{ currentYear }} TryPost. All rights reserved.
                </p>
            </div>
        </footer>
    </div>
</template>
