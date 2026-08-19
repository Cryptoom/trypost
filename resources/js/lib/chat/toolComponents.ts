import type { Component } from 'vue';

import ChatPostCard from '@/components/chat/tools/ChatPostCard.vue';
import ChatPostGenerationCard from '@/components/chat/tools/ChatPostGenerationCard.vue';
import ChatPostGenerationResult from '@/components/chat/tools/ChatPostGenerationResult.vue';
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
 * page turns into a new user message. `start_post_generation` is the first
 * `prompt` entry: its card collects the generation's choices client-side and
 * submits them as one readable sentence, so filling in a deterministic form
 * costs a single model turn instead of one per choice.
 *
 * `generate_post` is a plain `display` entry even though its card is the only
 * one that keeps working after it renders: the generation runs in the
 * background, so the card waits on a broadcast rather than asking the user
 * for anything.
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
    start_post_generation: { component: ChatPostGenerationCard, kind: 'prompt' },
    generate_post: { component: ChatPostGenerationResult, kind: 'display' },
};

export const resolveToolComponent = (toolName: string): ToolComponentEntry | null =>
    toolComponents[toolName] ?? null;
