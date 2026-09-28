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
  // Vite is the browser-facing server; Brace listens behind it on port 8080.
  server: {
    port: 4000,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: false,
      },
      '^/(?!@vite/|@id/|@fs/|src/|node_modules/|.*\\.(?:js|css|ts|tsx|scss|map)$).*': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: false,
      },
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: fileURLToPath(new URL('./src/main.ts', import.meta.url)),
      output: {
        entryFileNames: 'assets/app.js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: (asset) => asset.name?.endsWith('.css') ? 'assets/app.css' : 'assets/[name]-[hash][extname]',
      },
    },
  },
});
