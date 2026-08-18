import type { ComputedRef, InjectionKey } from 'vue';

export const appShellKey: InjectionKey<ComputedRef<boolean>> = Symbol('appShell');

const appShellPages = new Set(['accounts/Index', 'workspaces/Show']);

const appShellPrefixes = [
    'analytics/',
    'assets/',
    'automations/',
    'chat/',
    'labels/',
    'posts/',
    'settings/',
    'signatures/',
];

export const usesAppShell = (component: string): boolean => {
    if (appShellPages.has(component)) {
        return true;
    }

    return appShellPrefixes.some((prefix) => component.startsWith(prefix));
};
