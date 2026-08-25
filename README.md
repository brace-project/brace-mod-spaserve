# brace-mod-spaserve

Small Brace module for serving a Single Page Application and generating typed API stubs.

SPA-Surf does **not** build JavaScript and does **not** proxy Vite. In development Vite is expected to run in front of the PHP application. In production SPA-Surf serves already-built files from a bundle directory.

## SPA serving

```php
use Brace\SpaServe\SpaStaticFileServerMw;

$app->addMiddleware(new SpaStaticFileServerMw(
    bundleDir: __DIR__ . '/../frontend/dist',
    mount: '/',
    development: $app->environmentType === \Brace\Core\EnvironmentType::DEVELOPMENT,
    developmentIndexFile: __DIR__ . '/../frontend/index.html',
    viteEntry: '/src/main.ts',
    meta: [
        'api-base-url' => '/api',
        'assets-base-url' => '/',
    ],
));
```

Every non-asset URL below `mount` returns the SPA `index.html`. In production existing files below `bundleDir` are served directly. Missing asset-like URLs also fall back to the SPA shell; routing/not-found handling remains a frontend concern.

In development the returned HTML receives:

```html
<meta name="spa-base-path" content="/">
<meta name="spa-api-base-url" content="/api">
<meta name="spa-request-origin" content="https://example.test">
<script type="module" src="/@vite/client"></script>
<script type="module" src="/src/main.ts"></script>
```

Custom values from `meta` override the defaults. Values are escaped before being inserted into the document.

A frontend can read runtime configuration without rebuilding the generated API:

```ts
import { createAPI } from './generated-api';

const meta = (name: string) =>
  document.querySelector<HTMLMetaElement>(`meta[name="spa-${name}"]`)?.content;

export const API = createAPI({
  baseUrl: meta('api-base-url') || undefined,
});
```

This keeps deployment-specific hosts and prefixes out of generated TypeScript. For the common same-origin case no absolute host is needed.

## Vite development

Configure Vite as the public development server and proxy backend requests to Brace. For example:

```ts
// vite.config.ts
import { defineConfig } from 'vite';

export default defineConfig({
  server: {
    proxy: {
      '/api': 'http://127.0.0.1:8080',
      // SPA navigation is also forwarded to PHP so SPA-Surf can inject runtime meta values.
      '^/(?!@vite|src/)': 'http://127.0.0.1:8080',
    },
  },
});
```

The exact Vite proxy rules belong to the frontend project; SPA-Surf only emits the Vite client/entry includes in development mode.

## Production

Build the frontend before starting/deploying the PHP application and point `bundleDir` at that output directory:

```php
new SpaStaticFileServerMw(
    bundleDir: '/app/frontend/dist',
    mount: '/app',
    development: false,
    meta: ['api-base-url' => '/api'],
);
```

No build command is executed by SPA-Surf.

## TypeScript API generation

`Brace\SpaServe\Codegen\TypeScriptApiStubModule` uses `phore/schema` to inspect registered PHP callbacks and generates types/routes for `@trunkjs/api-stub`. The generated file is compared with the existing target and is only replaced when its content actually changes.
