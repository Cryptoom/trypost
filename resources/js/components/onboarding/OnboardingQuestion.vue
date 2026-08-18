<script setup lang="ts">
withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        streaming?: boolean;
    }>(),
    {
        title: undefined,
        description: undefined,
        streaming: false,
    },
);
</script>

<template>
    <div
        class="flex animate-in fade-in slide-in-from-bottom-2 items-start gap-2.5 duration-300 motion-reduce:animate-none"
    >
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
                {{ title }}<span
                    v-if="streaming && !description"
                    class="ms-0.5 inline-block h-3.5 w-1.5 translate-y-0.5 animate-pulse bg-foreground"
                    aria-hidden="true"
                />
            </p>
            <p
                v-if="description"
                class="mt-1 text-sm leading-relaxed text-muted-foreground"
            >
                {{ description }}<span
                    v-if="streaming"
                    class="ms-0.5 inline-block h-3 w-1.5 translate-y-0.5 animate-pulse bg-muted-foreground"
                    aria-hidden="true"
                />
            </p>
            <div v-if="$slots.default" :class="title || description ? 'mt-3' : undefined">
                <slot />
            </div>
        </div>
    </div>
</template>
