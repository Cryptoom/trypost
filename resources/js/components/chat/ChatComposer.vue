<script setup lang="ts">
import { IconArrowUp } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';

const model = defineModel<string>({ default: '' });

const props = defineProps<{
    placeholder: string;
    sendLabel: string;
    disabled?: boolean;
}>();

const emit = defineEmits<{
    submit: [];
}>();

const onSubmit = (): void => {
    if (props.disabled || ! model.value.trim()) {
        return;
    }

    emit('submit');
};

const onKeydown = (event: KeyboardEvent): void => {
    if (event.key === 'Enter' && ! event.shiftKey) {
        event.preventDefault();
        onSubmit();
    }
};
</script>

<template>
    <div
        class="flex items-end gap-2 rounded-3xl border-2 border-foreground bg-card p-2 shadow-2xs"
        data-testid="chat-composer"
        dusk="chat-composer"
    >
        <textarea
            v-model="model"
            rows="1"
            :placeholder="placeholder"
            class="min-h-10 flex-1 resize-none bg-transparent px-3 py-2 text-sm leading-relaxed text-foreground outline-none placeholder:text-muted-foreground"
            data-testid="chat-composer-input"
            dusk="chat-composer-input"
            @keydown="onKeydown"
        />
        <Button
            type="button"
            size="icon"
            class="rounded-full"
            :disabled="disabled || !model.trim()"
            :aria-label="sendLabel"
            data-testid="chat-send"
            dusk="chat-send"
            @click="onSubmit"
        >
            <IconArrowUp class="size-5" />
        </Button>
    </div>
</template>
