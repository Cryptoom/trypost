<script setup lang="ts">
import { Head, InfiniteScroll, Link, router } from '@inertiajs/vue3';
import { IconCopy, IconCopyPlus, IconDots, IconEyeOff, IconFileText, IconSearch, IconTrash } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

import {
    create as createPost,
    destroy as destroyPost,
    duplicate as duplicatePost,
    edit as editPost,
    index as postsIndex,
    show as showPost,
    unpublish as unpublishPost,
} from '@/actions/App/Http/Controllers/App/PostController';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import LabelBadge from '@/components/labels/LabelBadge.vue';
import LabelFilter from '@/components/labels/LabelFilter.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableLoadMore,
    TableRow,
} from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import { getPostStatusConfig } from '@/composables/usePostStatus';
import { useWorkspaceRole } from '@/composables/useWorkspaceRole';
import date from '@/date';
import debounce from '@/debounce';
import AppLayout from '@/layouts/AppLayout.vue';
import { copyToClipboard } from '@/lib/utils';
import { PostStatus } from '@/types/post';
interface SocialAccount {
    id: string;
    platform: string;
    display_name: string;
    username: string;
    display_label: string;
    avatar_url: string | null;
}

interface PostPlatform {
    id: string;
    social_account_id: string;
    enabled: boolean;
    platform: string;
    status: string;
    social_account: SocialAccount | null;
}

interface Label {
    id: string;
    name: string;
    color: string;
}

interface Post {
    id: string;
    content: string | null;
    status: string;
    scheduled_at: string | null;
    published_at: string | null;
    post_platforms: PostPlatform[];
    labels: Label[];
}

interface ScrollPosts {
    data: Post[];
    meta: {
        hasNextPage: boolean;
    };
}

interface Workspace {
    id: string;
    name: string;
}

interface Props {
    workspace: Workspace;
    posts: ScrollPosts;
    currentStatus: string | null;
    labels: Label[];
    filters: {
        search: string;
        labels: string[];
    };
}

const props = defineProps<Props>();

const searchQuery = ref(props.filters.search);
const selectedLabelIds = ref<string[]>(props.filters.labels ?? []);

const buildFilterUrl = () => {
    const url = props.currentStatus ? postsIndex.url(props.currentStatus) : postsIndex.url();
    router.get(
        url,
        {
            search: searchQuery.value || undefined,
            labels: selectedLabelIds.value.length ? selectedLabelIds.value : undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
};

const search = debounce(buildFilterUrl, 300);

watch(searchQuery, () => search());
watch(selectedLabelIds, () => buildFilterUrl(), { deep: true });

const pageTitle = computed(() => {
    if (props.currentStatus) {
        return trans(`posts.status.${props.currentStatus}`);
    }
    return trans('posts.all_posts');
});

const formatDateTime = (value: string | null): string => {
    if (!value) return '—';
    return date.formatDateTime(value);
};

const getEnabledPlatforms = (post: Post) => post.post_platforms.filter((pp) => pp.enabled);

const getPostPreview = (post: Post): string =>
    post.content?.trim() || trans('calendar.no_content');

const EDITABLE_STATUSES: readonly string[] = [PostStatus.Draft, PostStatus.Scheduled];
// Published/PartiallyPublished are deletable too: DeletePost runs a
// best-effort UnpublishPost first (removes the remote copies where the
// platform supports it), then always deletes the local post regardless of
// the remote outcome. Only Publishing (an in-flight publish job) is blocked
// server-side, see PostStatusRules::DELETE_BLOCKED_STATUSES.
const DELETABLE_STATUSES: readonly string[] = [
    PostStatus.Draft,
    PostStatus.Scheduled,
    PostStatus.Failed,
    PostStatus.Published,
    PostStatus.PartiallyPublished,
];
const UNPUBLISHABLE_STATUSES: readonly string[] = [PostStatus.Published, PostStatus.PartiallyPublished];
// Platforms whose publisher never gains a delete() method (see
// UnpublishPost::resolveDeletePublisher and CLAUDE.md): TikTok has no
// delete/unpublish endpoint at all, and Instagram's direct-login variant
// (not InstagramFacebook) only supports delete for Facebook-linked accounts.
// Mirrors accounts.instagram_connect.standalone_delete_notice.
const UNPUBLISH_UNSUPPORTED_PLATFORMS: readonly string[] = ['tiktok', 'instagram'];
const canEdit = (post: Post): boolean => EDITABLE_STATUSES.includes(post.status);
const canDelete = (post: Post): boolean => DELETABLE_STATUSES.includes(post.status);
const canUnpublish = (post: Post): boolean => UNPUBLISHABLE_STATUSES.includes(post.status);
// True once every enabled platform on the post is one that can never be
// unpublished automatically, i.e. the action would be a guaranteed no-op.
// A post mixing e.g. LinkedIn and TikTok keeps the action enabled; the
// backend still skips the unsupported rows and reports a partial outcome.
const unpublishUnsupported = (post: Post): boolean => {
    const enabled = getEnabledPlatforms(post);

    return enabled.length === 0 || enabled.every((pp) => UNPUBLISH_UNSUPPORTED_PLATFORMS.includes(pp.platform));
};

const { canCreatePost } = useWorkspaceRole();

const postUrl = (post: Post): string =>
    canEdit(post) ? editPost.url(post.id) : showPost.url(post.id);

const deleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);
const unpublishModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const handleDelete = (post: Post) => {
    deleteModal.value?.open({
        url: destroyPost.url(post.id),
        confirmText: trans('common.confirm_modal.delete_keyword'),
    });
};

const handleUnpublish = (post: Post) => {
    unpublishModal.value?.open({
        url: unpublishPost.url(post.id),
    });
};

const handleDuplicate = (post: Post) => {
    router.post(duplicatePost.url(post.id));
};

const handleCopyId = (post: Post) => copyToClipboard(post.id, trans('posts.actions.copied'));

const hasActiveSearch = computed(() => Boolean(searchQuery.value?.trim()));

const hasActiveFilters = computed(() => hasActiveSearch.value || selectedLabelIds.value.length > 0);

const refreshPosts = () => router.reload({ only: ['posts'], reset: ['posts'] });

useWorkspaceEcho(
    ['.post.created', '.post.deleted', '.post.platform.status.updated'],
    refreshPosts,
);
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <PageHeader :title="pageTitle" />

            <!-- Toolbar -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <div class="relative w-full sm:w-64">
                        <IconSearch class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-foreground/60" />
                        <Input
                            v-model="searchQuery"
                            :placeholder="trans('posts.search')"
                            class="w-full pl-9"
                        />
                    </div>

                    <LabelFilter v-if="labels.length" v-model="selectedLabelIds" :labels="labels" />
                </div>

                <Link v-if="canCreatePost" :href="createPost.url()" class="w-full sm:w-auto">
                    <Button class="w-full sm:w-auto">{{ $t('posts.new_post') }}</Button>
                </Link>
            </div>

            <EmptyState
                v-if="posts.data.length === 0"
                :icon="IconFileText"
                :title="hasActiveFilters ? $t('posts.no_search_results') : $t('posts.no_posts')"
                :description="hasActiveFilters ? $t('posts.try_different_search') : $t('posts.start_creating')"
            />

            <div v-else>
                <InfiniteScroll data="posts" items-element="#posts-body" preserve-url>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>{{ $t('posts.table.post') }}</TableHead>
                                <TableHead>{{ $t('posts.table.status') }}</TableHead>
                                <TableHead>{{ $t('posts.table.scheduled_at') }}</TableHead>
                                <TableHead class="text-right">{{ $t('posts.table.actions') }}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody id="posts-body">
                            <TableRow
                                v-for="post in posts.data"
                                :key="post.id"
                                class="cursor-pointer"
                                :data-testid="`post-row-${post.id}`"
                                @click="router.visit(postUrl(post))"
                            >
                                <TableCell class="max-w-md py-3">
                                    <div class="space-y-1.5">
                                        <div class="flex items-center gap-2">
                                            <div v-if="getEnabledPlatforms(post).length" class="flex -space-x-1.5">
                                                <TooltipProvider
                                                    v-for="pp in getEnabledPlatforms(post).slice(0, 4)"
                                                    :key="pp.id"
                                                    :delay-duration="200"
                                                >
                                                    <Tooltip>
                                                        <TooltipTrigger as-child>
                                                            <span class="inline-flex size-6 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                                                                <img
                                                                    :src="getPlatformLogo(pp.platform)"
                                                                    :alt="pp.platform"
                                                                    class="size-full object-cover"
                                                                />
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                            <div class="space-y-0.5 text-xs">
                                                                <p class="font-semibold">
                                                                    {{ pp.social_account?.display_label ?? pp.platform }}<span
                                                                        v-if="pp.social_account?.username"
                                                                        class="font-normal opacity-80"
                                                                    >&nbsp;·&nbsp;@{{ pp.social_account.username }}</span>
                                                                </p>
                                                                <p class="opacity-70">{{ getPlatformLabel(pp.platform) }}</p>
                                                            </div>
                                                        </TooltipContent>
                                                    </Tooltip>
                                                </TooltipProvider>
                                            </div>
                                            <span
                                                v-if="getEnabledPlatforms(post).length > 4"
                                                class="text-xs font-bold text-foreground/60"
                                            >+{{ getEnabledPlatforms(post).length - 4 }}</span>
                                            <div v-if="post.labels?.length" class="ml-1 flex flex-wrap items-center gap-1">
                                                <LabelBadge
                                                    v-for="label in post.labels.slice(0, 3)"
                                                    :key="label.id"
                                                    :label="label"
                                                />
                                                <span v-if="post.labels.length > 3" class="text-xs font-bold text-foreground/60">
                                                    +{{ post.labels.length - 3 }}
                                                </span>
                                            </div>
                                        </div>
                                        <p class="truncate text-foreground/80">{{ getPostPreview(post) }}</p>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="getPostStatusConfig(post.status).variant">
                                        <component :is="getPostStatusConfig(post.status).icon" class="size-3" />
                                        {{ getPostStatusConfig(post.status).label }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    {{ formatDateTime(post.scheduled_at ?? post.published_at) }}
                                </TableCell>
                                <TableCell class="text-right" @click.stop>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                class="size-8"
                                                :data-testid="`post-actions-${post.id}`"
                                                @click.stop
                                            >
                                                <IconDots class="size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem v-if="canCreatePost" @click="handleDuplicate(post)">
                                                <IconCopyPlus class="size-4" />
                                                {{ $t('posts.actions.duplicate') }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem @click="handleCopyId(post)">
                                                <IconCopy class="size-4" />
                                                {{ $t('posts.actions.copy_id') }}
                                            </DropdownMenuItem>
                                            <template v-if="canCreatePost && (canUnpublish(post) || canDelete(post))">
                                                <DropdownMenuSeparator />
                                                <template v-if="canUnpublish(post)">
                                                    <TooltipProvider v-if="unpublishUnsupported(post)" :delay-duration="200">
                                                        <Tooltip>
                                                            <TooltipTrigger as-child>
                                                                <span class="block">
                                                                    <DropdownMenuItem
                                                                        disabled
                                                                        :data-testid="`post-unpublish-${post.id}`"
                                                                    >
                                                                        <IconEyeOff class="size-4" />
                                                                        {{ $t('posts.actions.unpublish') }}
                                                                    </DropdownMenuItem>
                                                                </span>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                <p class="max-w-64 text-xs">{{ $t('posts.actions.unpublish_unsupported') }}</p>
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                    <DropdownMenuItem
                                                        v-else
                                                        :data-testid="`post-unpublish-${post.id}`"
                                                        @click="handleUnpublish(post)"
                                                    >
                                                        <IconEyeOff class="size-4" />
                                                        {{ $t('posts.actions.unpublish') }}
                                                    </DropdownMenuItem>
                                                </template>
                                                <DropdownMenuItem
                                                    v-if="canDelete(post)"
                                                    variant="destructive"
                                                    :data-testid="`post-delete-${post.id}`"
                                                    @click="handleDelete(post)"
                                                >
                                                    <IconTrash class="size-4" />
                                                    {{ $t('posts.actions.delete') }}
                                                </DropdownMenuItem>
                                            </template>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <template #next="{ loading }">
                        <TableLoadMore v-if="loading" />
                    </template>
                </InfiniteScroll>
            </div>
        </div>
    </AppLayout>

    <ConfirmDeleteModal
        ref="deleteModal"
        testid="post-delete-modal"
        :title="$t('posts.edit.delete_modal.title')"
        :description="$t('posts.edit.delete_modal.description')"
        :action="$t('posts.edit.delete_modal.action')"
        :cancel="$t('posts.edit.delete_modal.cancel')"
    />

    <ConfirmDeleteModal
        ref="unpublishModal"
        method="post"
        testid="post-unpublish-modal"
        :title="$t('posts.edit.unpublish_modal.title')"
        :description="$t('posts.edit.unpublish_modal.description')"
        :action="$t('posts.edit.unpublish_modal.action')"
        :cancel="$t('posts.edit.unpublish_modal.cancel')"
    />

</template>
