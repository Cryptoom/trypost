<script setup lang="ts">
import { computed, provide } from 'vue';

import AppSidebar from '@/components/AppSidebar.vue';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { appShellKey } from '@/lib/appShell';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
    defineProps<{
        sidebarOpen?: boolean;
    }>(),
    {
        sidebarOpen: true,
    },
);

provide(appShellKey, computed(() => true));
</script>

<template>
    <SidebarProvider :default-open="sidebarOpen">
        <AppSidebar />
        <SidebarInset class="overflow-x-hidden">
            <slot />
        </SidebarInset>
    </SidebarProvider>
</template>
