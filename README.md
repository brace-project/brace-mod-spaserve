# brace-mod-spaserve

Small Brace module for serving a Single Page Application and generating typed TypeScript API stubs.

SPA-Surf does not build JavaScript and does not proxy Vite. Development uses Vite in front of Brace; production serves already-built files from a bundle directory.

## Setup

Configure the mode explicitly from the application environment and create the matching HTML generator:

```php
use Brace\Core\EnvironmentType;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;

$development = $app->environmentType === EnvironmentType::DEVELOPMENT;

$html = $development
    ? ViteAutoHtml::development(
        entrypoint: '/src/main.ts',
        basePath: '/',
        meta: ['spa-api-base-url' => '/api'],
        startElement: 'demo-app',
    )
    : ViteAutoHtml::production(
        basePath: '/',
        meta: ['spa-api-base-url' => '/api'],
        css: ['/assets/app.css'],
        javascript: ['/assets/app.js'],
        startElement: 'demo-app',
    );

$app->addMiddleware(new SpaStaticFileServerMw(
    bundleDir: __DIR__ . '/../frontend/dist',
    html: $html,
    mount: '/',
));
```

`ViteAutoHtml::development()` adds `/@vite/client` and the configured entrypoint. `ViteAutoHtml::production()` never reads a Vite manifest; production CSS and JavaScript files are supplied directly.

Both modes generate runtime meta fields such as:

```html
<meta name="spa-base-path" content="/">
<meta name="spa-api-base-url" content="/api">
```

Explicit `meta` values override the automatic defaults. `startElement` inserts the custom element that starts the SPA.

## Vite development proxy

A minimal Vite configuration forwards API and SPA navigation requests to Brace:

```ts
import { defineConfig } from 'vite';

export default defineConfig({
  server: {
    proxy: {
      '/api': 'http://127.0.0.1:8080',
      '^/(?!@vite|src/)': 'http://127.0.0.1:8080',
    },
  },
});
```

A complete minimal setup is available in [`examples/demo`](examples/demo).

## TypeScript API generation

`Brace\SpaServe\Codegen\TypeScriptApiStubModule` uses `phore/schema` to inspect PHP callbacks and generates the types and route table consumed by `@trunkjs/api-stub`.

```php
$api = new \Brace\SpaServe\Codegen\TypeScriptApiStubModule(
    targetFile: __DIR__ . '/../frontend/src/generated-api.ts',
);

$api->route(
    name: 'User.Get',
    path: '/api/users/{userId}',
    methods: 'GET',
    callback: [UserController::class, 'get'],
);

$api->load();
```

The target file can be checked on every application load. It is only written when the generated content actually changed.

## Utilities

HTML generation lives in `Brace\SpaServe\Html\HtmlGenerator` and `ViteAutoHtml`. Static MIME lookup lives in `Brace\SpaServe\Tools\MimeMap`; unknown extensions use `application/octet-stream`.
