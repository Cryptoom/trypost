<script setup lang="ts">
import { computed } from 'vue';

import { renderChatMarkdown } from '@/lib/chat/markdown';

const props = withDefaults(
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

const CURSOR_MARKUP =
    '<span class="chat-markdown-cursor ms-0.5 inline-block h-3 w-1.5 translate-y-0.5 animate-pulse bg-muted-foreground" aria-hidden="true"></span>';

// Tags that only ever wrap other elements (never text directly) — a bare
// cursor <span> can't legally sit inside them, so appendStreamingCursor()
// steps back out past these before inserting.
const CONTAINER_ONLY_TAGS = new Set(['ul', 'ol', 'table', 'thead', 'tbody', 'tr']);

/**
 * v-html replaces all children of the target element, so the cursor can't
 * simply live in the template next to the interpolation like it did for plain
 * text. Splicing it before the last closing tag lands it inside whatever
 * element the markdown ended in (a paragraph, a list item, a code block...),
 * so it sits at the end of the visible text instead of dropping onto its own
 * line after a block-level element. markdown-it separates block tags with
 * newlines, so whitespace is walked past (and preserved in `suffix`) at each
 * step. If the closing tag found belongs to a container-only element (e.g. a
 * list ending in `</ul>`), step back to the closing tag before it (`</li>`)
 * so the cursor lands inside real content instead of as an invalid direct
 * child of the list.
 */
const appendStreamingCursor = (html: string): string => {
    let head = html;
    let suffix = '';

    const stripTrailingWhitespace = (): void => {
        const whitespace = head.match(/\s+$/);

        if (whitespace) {
            suffix = whitespace[0] + suffix;
            head = head.slice(0, head.length - whitespace[0].length);
        }
    };

    stripTrailingWhitespace();

    let containerMatch = head.match(/<\/([a-z][a-z0-9]*)>$/i);

    while (containerMatch && CONTAINER_ONLY_TAGS.has(containerMatch[1].toLowerCase())) {
        suffix = containerMatch[0] + suffix;
        head = head.slice(0, head.length - containerMatch[0].length);
        stripTrailingWhitespace();
        containerMatch = head.match(/<\/([a-z][a-z0-9]*)>$/i);
    }

    const closingTag = head.match(/<\/[a-z][a-z0-9]*>$/i);

    if (!closingTag) {
        return `${head}${CURSOR_MARKUP}${suffix}`;
    }

    const insertAt = head.length - closingTag[0].length;

    return `${head.slice(0, insertAt)}${CURSOR_MARKUP}${head.slice(insertAt)}${suffix}`;
};

const descriptionHtml = computed(() => {
    if (!props.description) {
        return '';
    }

    const html = renderChatMarkdown(props.description);

    return props.streaming ? appendStreamingCursor(html) : html;
});
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
            <div
                v-if="description"
                class="prose prose-sm prose-chat mt-1 max-w-none"
                v-html="descriptionHtml"
            />
            <div
                v-if="$slots.default"
                :class="title || description ? 'mt-3' : undefined"
            >
                <slot />
            </div>
        </div>
    </div>
</template>

<style scoped>
.prose-chat {
    --tw-prose-body: var(--color-muted-foreground);
    --tw-prose-headings: var(--color-muted-foreground);
    --tw-prose-links: var(--color-muted-foreground);
    --tw-prose-bold: var(--color-muted-foreground);
    --tw-prose-counters: var(--color-muted-foreground);
    --tw-prose-bullets: var(--color-muted-foreground);
    --tw-prose-quotes: var(--color-muted-foreground);
    --tw-prose-quote-borders: var(--color-muted-foreground);
    --tw-prose-captions: var(--color-muted-foreground);
    --tw-prose-code: var(--color-muted-foreground);
    --tw-prose-pre-code: var(--color-muted-foreground);
    --tw-prose-pre-bg: var(--color-muted);
    --tw-prose-th-borders: var(--color-border);
    --tw-prose-td-borders: var(--color-border);
}

.prose-chat :deep(p) {
    margin-block: 0;
}

.prose-chat :deep(p + p),
.prose-chat :deep(ul),
.prose-chat :deep(ol),
.prose-chat :deep(pre),
.prose-chat :deep(blockquote) {
    margin-block: 0.5em;
}

.prose-chat :deep(li) {
    margin-block: 0.125em;
}

.prose-chat :deep(code) {
    background-color: var(--color-muted);
    border-radius: 0.25rem;
    padding-inline: 0.35em;
    padding-block: 0.05em;
    font-weight: 400;
}

.prose-chat :deep(code)::before,
.prose-chat :deep(code)::after {
    content: none;
}

.prose-chat :deep(pre code) {
    background-color: transparent;
    padding: 0;
    border-radius: 0;
}
</style>
