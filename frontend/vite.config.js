import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

// Builds from frontend/src into assets/dist so the existing
// server-rendered pages can reference /assets/dist/*.{css,js}
export default defineConfig({
    plugins: [tailwindcss()],
    build: {
        outDir: '../assets/dist',
        emptyOutDir: true,
        manifest: true,
        cssCodeSplit: false,
        assetsDir: 'assets',
        rollupOptions: {
            input: {
                app: resolve(import.meta.dirname, 'src/main.js'),
            },
        },
    },
});