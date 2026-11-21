import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import { run } from 'vite-plugin-run';

export default defineConfig({
  plugins: [
    run({
      name: 'Brace API stub',
      run: ['../../vendor/bin/brace', 'spa-api-build'],
      startup: true,
      build: true,
    }),
  ],
  // Development only: Vite forwards application requests unchanged to Brace.
  server: {
    port: 4000,
    strictPort: true,
    host: "0.0.0.0",
    allowedHosts: true,
    proxy: {
      "^(?!/(?:e/)?@vite|/(?:e/)?@fs/|/(?:e/)?@id/|/e/src/|/docs/_src/|/node_modules/|/workspaces/|.*\\.(?:js|css|ts|tsx|vue|svelte|scss|sass|less|map)(?:\\?.*)?$).*": {
        target: "http://127.0.0.1:80",
        changeOrigin: false,
        autoRewrite: true,
      },
    },
    hmr: true,
  },
  build: {
    outDir: 'assets/dist',
    emptyOutDir: true,
    rollupOptions: {
      input: fileURLToPath(new URL('./src/main.ts', import.meta.url)),
      output: {
        entryFileNames: 'app.js',
        chunkFileNames: '[name]-[hash].js',
        assetFileNames: (asset) => asset.name?.endsWith('.css') ? 'app.css' : '[name]-[hash][extname]',
      },
    },
  },
});
