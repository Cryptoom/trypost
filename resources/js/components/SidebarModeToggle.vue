<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconLayoutGrid, IconMessage } from '@tabler/icons-vue';
import { computed } from 'vue';

import { TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { calendar, chat } from '@/routes/app';

const { urlIsActive } = useActiveUrl();

const isChatMode = computed(() => urlIsActive(chat.url(), { prefix: true }));
</script>

<template>
    <TabsList
        class="mx-2 grid w-[calc(100%-1rem)] max-w-none grid-cols-2 gap-1 overflow-visible rounded-lg border-2 border-foreground bg-muted p-1"
        data-testid="sidebar-mode-toggle"
        dusk="sidebar-mode-toggle"
    >
        <TabsTrigger
            value="browse"
            as-child
            class="h-8 text-xs uppercase tracking-wide"
        >
            <Link
                :href="calendar.url()"
                :tabindex="isChatMode ? 0 : -1"
                data-testid="sidebar-browse"
                dusk="sidebar-browse"
                @click="
                    (event) => {
                        if (! isChatMode) {
                            event.preventDefault();
                        }
                    }
                "
            >
                <IconLayoutGrid class="size-3.5" />
                {{ $t('sidebar.browse') }}
            </Link>
        </TabsTrigger>
        <TabsTrigger
            value="chat"
            as-child
            class="h-8 text-xs uppercase tracking-wide"
        >
            <Link
                :href="chat.url()"
                :tabindex="isChatMode ? -1 : 0"
                data-testid="sidebar-chat"
                dusk="sidebar-chat"
                @click="
                    (event) => {
                        if (isChatMode) {
                            event.preventDefault();
                        }
                    }
                "
            >
                <IconMessage class="size-3.5" />
                {{ $t('sidebar.chat') }}
            </Link>
        </TabsTrigger>
    </TabsList>
</template>
