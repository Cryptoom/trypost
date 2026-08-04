<script setup lang="ts">
defineProps<{
    title: string;
    description?: string;
    total?: number;
}>();
</script>

<template>
    <header class="space-y-1">
        <h1
            class="text-2xl leading-tight font-semibold text-foreground sm:text-4xl"
            style="font-family: var(--font-display)"
        >
            {{ title }}
            <span v-if="total != null && total > 0" class="text-foreground/40"
                >({{ total }})</span
            >
        </h1>
        <p v-if="description" class="text-sm text-foreground/70">
            {{ description }}
        </p>
    </header>
</template>
