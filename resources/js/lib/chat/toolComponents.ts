import type { Component } from 'vue';

import ChatPostCard from '@/components/chat/tools/ChatPostCard.vue';
import ChatPostList from '@/components/chat/tools/ChatPostList.vue';
import ChatPostMetrics from '@/components/chat/tools/ChatPostMetrics.vue';

export type ToolComponentKind = 'display' | 'prompt';

export type ToolComponentEntry = {
    component: Component;
    kind: ToolComponentKind;
};

/**
 * Maps a tool name to the component that renders its output. `display`
 * components only render; `prompt` components also emit `submit`, which the
 * page turns into a new user message. v1 registers only `display` entries —
 * the second kind exists so the post-creation wizard can move into the chat
 * without repainting this contract.
 */
export const toolComponents: Record<string, ToolComponentEntry> = {
    list_posts: { component: ChatPostList, kind: 'display' },
    get_post: { component: ChatPostCard, kind: 'display' },
    get_post_metrics: { component: ChatPostMetrics, kind: 'display' },
    create_post: { component: ChatPostCard, kind: 'display' },
    update_post: { component: ChatPostCard, kind: 'display' },
    schedule_post: { component: ChatPostCard, kind: 'display' },
    publish_post: { component: ChatPostCard, kind: 'display' },
    delete_post: { component: ChatPostCard, kind: 'display' },
};

export const resolveToolComponent = (toolName: string): ToolComponentEntry | null =>
    toolComponents[toolName] ?? null;
