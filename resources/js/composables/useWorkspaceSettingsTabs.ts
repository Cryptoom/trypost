import { computed } from 'vue';
import { trans } from 'laravel-vue-i18n';

import { members as membersRoute } from '@/routes/app';
import { index as apiKeysRoute } from '@/routes/app/api-keys';
import { index as mcpRoute } from '@/routes/app/mcp';
import { brand as brandRoute, settings as workspaceSettings } from '@/routes/app/workspace';

export const useWorkspaceSettingsTabs = () =>
    computed(() => [
        {
            name: 'workspace',
            label: trans('settings.workspace.tabs.workspace'),
            href: workspaceSettings.url(),
        },
        {
            name: 'brand',
            label: trans('settings.workspace.tabs.brand'),
            href: brandRoute.url(),
        },
        {
            name: 'members',
            label: trans('settings.workspace.tabs.users'),
            href: membersRoute.url(),
        },
        {
            name: 'api-keys',
            label: trans('settings.workspace.tabs.api_keys'),
            href: apiKeysRoute.url(),
        },
        {
            name: 'mcp',
            label: trans('settings.workspace.tabs.mcp'),
            href: mcpRoute.url(),
        },
    ]);
