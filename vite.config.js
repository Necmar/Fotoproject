import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/main.jsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    build: {
        // Static files only: the production build runs locally or in CI and is
        // uploaded to Plesk. No Node.js process is needed on the server.
        chunkSizeWarningLimit: 800,
        rolldownOptions: {
            output: {
                // Libraries change rarely: in their own chunk, a deploy of app code keeps them cached.
                codeSplitting: {
                    groups: [
                        {
                            name: 'vendor',
                            test: /node_modules[\\/](react|react-dom|scheduler|react-router|i18next|react-i18next|axios)[\\/]/,
                            priority: 20,
                        },
                    ],
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
