<script setup lang="ts">
withDefaults(
    defineProps<{
        title?: string;
        description?: string;
    }>(),
    {
        title: undefined,
        description: undefined,
    },
);
</script>

<template>
    <div class="flex items-start gap-2.5">
        <img
            src="/images/trypost/icon.png"
            alt=""
            class="mt-0.5 size-7 shrink-0 rounded-full border-2 border-foreground shadow-2xs"
        />
        <div
            class="min-w-0 max-w-[90%] rounded-2xl rounded-tl-md border-2 border-foreground bg-card px-3.5 py-2.5 shadow-2xs"
        >
            <p
                v-if="title"
                class="text-sm font-bold tracking-tight text-foreground"
            >
                {{ title }}
            </p>
            <p
                v-if="description"
                class="mt-1 text-sm leading-relaxed text-muted-foreground"
            >
                {{ description }}
            </p>
            <div v-if="$slots.default" :class="title || description ? 'mt-3' : undefined">
                <slot />
            </div>
        </div>
    </div>
</template>
