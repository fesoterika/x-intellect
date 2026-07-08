import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

// Vite используется только локально/в CI: на хостинг заливаются уже
// собранные файлы public/build, Node на сервере не нужен (Этап 1 плана)
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/admin.js',
            ],
            refresh: true,
        }),
    ],
});
