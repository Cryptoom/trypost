import { createInertiaApp } from '@inertiajs/vue3';
import createServer from '@inertiajs/vue3/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createSSRApp, DefineComponent, h } from 'vue';
import { renderToString } from 'vue/server-renderer';

import { usesAppShell } from './lib/appShell';
import { syncContentTypeMediaRules } from './lib/contentTypeMediaRules';
import AppShell from './layouts/AppShell.vue';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createServer(
    (page) =>
        createInertiaApp({
            page,
            render: renderToString,
            title: (title) => (title ? `${title} - ${appName}` : appName),
            layout: (name) => (usesAppShell(name) ? AppShell : undefined),
            resolve: (name) =>
                resolvePageComponent(
                    `./pages/${name}.vue`,
                    import.meta.glob<DefineComponent>('./pages/**/*.vue'),
                ),
            setup: ({ App, props, plugin }) => {
                syncContentTypeMediaRules(props.initialPage);

                return createSSRApp({ render: () => h(App, props) }).use(plugin);
            },
        }),
    { cluster: true },
);
