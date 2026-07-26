<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    IconAffiliate,
    IconAlertTriangle,
    IconBolt,
    IconBrandX,
    IconCalendar,
    IconChartBar,
    IconChevronRight,
    IconClock,
    IconFileCheck,
    IconFileText,
    IconGift,
    IconHash,
    IconLifebuoy,
    IconListCheck,
    IconPencil,
    IconPhoto,
    IconSelector,
    IconTag,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import {
    create as createPost,
    index as postsIndex,
} from '@/actions/App/Http/Controllers/App/PostController';
import NavMain from '@/components/NavMain.vue';
import NavSupport from '@/components/NavSupport.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import WorkspaceMenuContent from '@/components/WorkspaceMenuContent.vue';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { useOnboardingResidualEcho } from '@/composables/echo/useOnboardingStatusEcho';
import { useWorkspaceRole } from '@/composables/useWorkspaceRole';
import { accounts, analytics, calendar, onboarding } from '@/routes/app';
import { index as assets } from '@/routes/app/assets';
import { index as automations } from '@/routes/app/automations';
import { portal } from '@/routes/app/billing';
import { index as labels } from '@/routes/app/labels';
import { index as signatures } from '@/routes/app/signatures';
import type { NavItem, OnboardingResidual, User } from '@/types';

interface Workspace {
    id: string;
    name: string;
    logo_url: string | null;
}

const page = usePage();
const user = computed(() => page.props.auth.user as User);
const currentWorkspace = computed<Workspace | null>(
    () => page.props.auth.currentWorkspace as Workspace | null,
);
const workspaces = computed<Workspace[]>(
    () => page.props.auth.workspaces as Workspace[],
);
const subscriptionPastDue = computed<boolean>(() =>
    Boolean(page.props.auth.subscriptionPastDue),
);
const onboardingResidual = computed<OnboardingResidual | false>(
    () => page.props.onboardingResidual,
);
const showOnboardingNav = computed(() => onboardingResidual.value !== false);
const onboardingProgress = computed(() => {
    if (onboardingResidual.value === false) {
        return 0;
    }

    const { completed, total } = onboardingResidual.value;

    return total > 0 ? Math.round((completed / total) * 100) : 0;
});

useOnboardingResidualEcho();

const {
    canCreatePost,
    canManageAccounts,
    canManageAutomations,
    canCreateWorkspace,
} = useWorkspaceRole();
const { urlIsActive } = useActiveUrl();
const { isMobile } = useSidebar();

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: trans('sidebar.posts.calendar'),
        href: calendar.url(),
        icon: IconCalendar,
    },
    {
        title: trans('sidebar.analytics'),
        href: analytics.url(),
        icon: IconChartBar,
    },
    ...(canManageAutomations.value
        ? [
              {
                  title: trans('sidebar.automations'),
                  href: automations.url(),
                  icon: IconBolt,
                  badge: trans('common.beta'),
              },
          ]
        : []),
]);

const postsNavItems = computed<NavItem[]>(() => [
    {
        title: trans('sidebar.posts.all'),
        href: postsIndex.url(),
        icon: IconFileText,
        excludeActive: [
            postsIndex.url('scheduled'),
            postsIndex.url('published'),
            postsIndex.url('draft'),
        ],
    },
    {
        title: trans('sidebar.posts.scheduled'),
        href: postsIndex.url('scheduled'),
        icon: IconClock,
    },
    {
        title: trans('sidebar.posts.posted'),
        href: postsIndex.url('published'),
        icon: IconFileCheck,
    },
    {
        title: trans('sidebar.posts.drafts'),
        href: postsIndex.url('draft'),
        icon: IconPencil,
    },
]);

const workspaceNavItems = computed<NavItem[]>(() => [
    ...(canManageAccounts.value
        ? [
              {
                  title: trans('sidebar.workspace.connections'),
                  href: accounts.url(),
                  icon: IconAffiliate,
              },
          ]
        : []),
    ...(canCreatePost.value
        ? [
              {
                  title: trans('sidebar.workspace.signatures'),
                  href: signatures.url(),
                  icon: IconHash,
              },
              {
                  title: trans('sidebar.workspace.labels'),
                  href: labels.url(),
                  icon: IconTag,
              },
              {
                  title: trans('sidebar.workspace.assets'),
                  href: assets.url(),
                  icon: IconPhoto,
              },
          ]
        : []),
]);

const supportNavItems = computed(() => [
    {
        title: trans('sidebar.support.referral'),
        href: 'https://affiliates.trypost.it/',
        icon: IconGift,
    },
    {
        title: trans('sidebar.support.stay_updated'),
        href: 'https://x.com/trypostit',
        icon: IconBrandX,
    },
    {
        title: trans('sidebar.support.docs'),
        href: 'https://docs.trypost.it',
        icon: IconLifebuoy,
    },
]);
</script>

<template>
    <Sidebar collapsible="offcanvas">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <div class="flex items-center gap-1">
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <SidebarMenuButton
                                    size="lg"
                                    class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                                    data-test="sidebar-menu-button"
                                    dusk="sidebar-workspace-menu"
                                >
                                    <Avatar
                                        :src="currentWorkspace?.logo_url"
                                        :name="currentWorkspace?.name ?? '?'"
                                        class="h-8 w-8 shrink-0 rounded-md border-2 border-foreground"
                                        fallback-class="bg-violet-100 text-violet-700 font-bold"
                                    />
                                    <div
                                        class="grid min-w-0 flex-1 text-left text-sm leading-tight"
                                    >
                                        <span class="truncate font-semibold">
                                            {{
                                                currentWorkspace?.name ??
                                                $t('sidebar.select_workspace')
                                            }}
                                        </span>
                                    </div>
                                    <component
                                        :is="
                                            isMobile
                                                ? IconSelector
                                                : IconChevronRight
                                        "
                                        class="ml-auto size-4"
                                    />
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                class="w-(--reka-dropdown-menu-trigger-width) min-w-64"
                                align="start"
                                :side="isMobile ? 'bottom' : 'right'"
                                :side-offset="4"
                            >
                                <WorkspaceMenuContent
                                    :user="user"
                                    :current-workspace="currentWorkspace"
                                    :workspaces="workspaces"
                                    :can-create-workspace="canCreateWorkspace"
                                />
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <NotificationBell v-if="currentWorkspace" />
                    </div>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-px">
            <div v-if="currentWorkspace && canCreatePost" class="px-2 py-2">
                <Link :href="createPost.url()" class="block">
                    <Button class="w-full">
                        {{ $t('sidebar.create_post') }}
                    </Button>
                </Link>
            </div>

            <NavMain v-if="currentWorkspace" :items="mainNavItems" />
            <NavMain
                v-if="currentWorkspace"
                :items="postsNavItems"
                :label="$t('sidebar.groups.posts')"
            />
            <NavMain
                v-if="currentWorkspace && workspaceNavItems.length"
                :items="workspaceNavItems"
                :label="$t('sidebar.groups.workspace')"
            />
            <NavSupport
                v-if="currentWorkspace"
                :items="supportNavItems"
                :label="$t('sidebar.groups.others')"
            />
        </SidebarContent>

        <SidebarFooter>
            <div
                v-if="
                    currentWorkspace &&
                    showOnboardingNav &&
                    onboardingResidual !== false
                "
                class="px-1 pb-1 group-data-[collapsible=icon]:hidden"
            >
                <Link
                    :href="onboarding.url()"
                    dusk="sidebar-onboarding"
                    :class="[
                        'block rounded-lg border-2 border-foreground p-3 shadow-2xs transition-colors',
                        urlIsActive(onboarding.url())
                            ? 'bg-amber-200'
                            : 'bg-amber-100 hover:bg-amber-200',
                    ]"
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <span
                                class="inline-flex size-7 shrink-0 items-center justify-center rounded-md border-2 border-foreground bg-card shadow-2xs"
                            >
                                <IconListCheck
                                    class="size-4 text-amber-800"
                                    stroke-width="2.5"
                                />
                            </span>
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-bold text-foreground"
                                >
                                    {{ $t('sidebar.onboarding') }}
                                </p>
                                <p
                                    class="truncate text-xs text-foreground/70"
                                >
                                    {{ $t('sidebar.onboarding_hint') }}
                                </p>
                            </div>
                        </div>
                        <span
                            class="shrink-0 rounded-full border-2 border-foreground bg-card px-1.5 py-0.5 text-[10px] font-bold tabular-nums"
                        >
                            {{ onboardingResidual.completed }}/{{
                                onboardingResidual.total
                            }}
                        </span>
                    </div>
                    <div
                        class="mt-2.5 h-1.5 overflow-hidden rounded-full border border-foreground bg-card"
                        role="progressbar"
                        :aria-valuenow="onboardingResidual.completed"
                        :aria-valuemin="0"
                        :aria-valuemax="onboardingResidual.total"
                    >
                        <div
                            class="h-full bg-foreground transition-[width]"
                            :style="{ width: `${onboardingProgress}%` }"
                        />
                    </div>
                </Link>
            </div>

            <div
                v-if="subscriptionPastDue"
                dusk="past-due-notice"
                class="mx-1 mb-1 rounded-md border-2 border-destructive bg-destructive/10 p-3"
            >
                <div class="flex items-center gap-2 text-destructive">
                    <IconAlertTriangle class="size-4 shrink-0" />
                    <span class="text-sm font-semibold">{{
                        $t('billing.past_due_notice.title')
                    }}</span>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ $t('billing.past_due_notice.description') }}
                </p>
                <Button
                    as="a"
                    :href="portal.url()"
                    variant="destructive"
                    size="sm"
                    class="mt-2 w-full"
                    dusk="past-due-cta"
                >
                    {{ $t('billing.past_due_notice.cta') }}
                </Button>
            </div>
        </SidebarFooter>
    </Sidebar>
</template>
