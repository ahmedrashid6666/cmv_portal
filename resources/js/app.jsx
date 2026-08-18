import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

// Deliberately NOT import.meta.env.VITE_APP_NAME: Vite inlines env vars at
// build time, so the committed bundle would carry the app name of whoever ran
// `npm run build` onto every deployment. The server renders this meta tag per
// request instead, which keeps the bundle brand-free.
const appName = document.querySelector('meta[name="app-name"]')?.content ?? '';

createInertiaApp({
    title: (title) => (appName ? `${title} - ${appName}` : title),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
