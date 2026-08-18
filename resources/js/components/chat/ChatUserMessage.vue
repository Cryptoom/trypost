<script setup lang="ts">
defineProps<{
    text: string;
}>();
</script>

<template>
    <div
        class="flex animate-in fade-in slide-in-from-bottom-2 justify-end duration-300 motion-reduce:animate-none"
    >
        <div
            class="min-w-0 max-w-[90%] rounded-2xl rounded-tr-md border-2 border-foreground bg-amber-100 px-3.5 py-2.5 shadow-2xs"
        >
            <p class="text-sm leading-relaxed text-foreground">
                {{ text }}
            </p>
        </div>
    </div>
</template>
