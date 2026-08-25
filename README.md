# brace-mod-spaserve

Small Brace module for serving Single Page Applications and generating typed TypeScript API stubs.

SPA-Surf deliberately does **not** build JavaScript and does **not** implement a Vite proxy. In development, Vite is expected to run in front of the PHP application. In production, SPA-Surf serves already-built files from a configured bundle directory.

## HTML generation

HTML creation is separated from HTTP serving.

### Generic HTML

`Brace\SpaServe\Html\HtmlGenerator` creates a minimal document from title, meta fields, stylesheets, module scripts and an optional custom element used to start the SPA.

```php
use Brace\SpaServe\Html\HtmlGenerator;

$html = new HtmlGenerator(
    title: 'Admin',
    meta: [
        'spa-api-base-url' => '/api',
    ],
    css: [
        '/assets/app.css',
    ],
    javascript: [
        '/assets/app.js',
    ],
    startElement: [
        'tag' => 'admin-app',
        'attributes' => [
            'theme' => 'dark',
        ],
    ],
);
```

The start element may also simply be a tag name:

```php
startElement: 'admin-app'
```

### Vite development

`ViteAutoHtml::development()` automatically adds the Vite client and entrypoint. It also sets sensible SPA meta defaults from `basePath`.

```php
use Brace\SpaServe\Html\ViteAutoHtml;

$html = ViteAutoHtml::development(
    entrypoint: '/src/main.ts',
    basePath: '/admin',
    title: 'Admin',
    meta: [
        // Explicit values override automatically generated defaults.
        'spa-api-base-url' => '/api',
    ],
    css: [
        'src/app.css',
    ],
    startElement: 'admin-app',
);
```

This produces the equivalent of:

```html
<meta name="spa-base-path" content="/admin/">
<meta name="spa-api-base-url" content="/api">
<script type="module" src="/@vite/client"></script>
<script type="module" src="/src/main.ts"></script>
<admin-app></admin-app>
```

Relative CSS/JavaScript paths are resolved against `basePath`. Absolute paths and full URLs are left untouched.

### Production HTML

`ViteAutoHtml::production()` does not inspect or parse a Vite manifest. Built files are supplied explicitly.

```php
$html = ViteAutoHtml::production(
    basePath: '/admin',
    title: 'Admin',
    meta: [
        'spa-api-base-url' => '/api',
    ],
    css: [
        'assets/app.css',
    ],
    javascript: [
        'assets/app.js',
    ],
    startElement: 'admin-app',
);
```

How filenames are obtained is intentionally outside SPA-Surf. A deployment, build script or other application code can provide them.

## SPA serving

`SpaStaticFileServerMw` only handles HTTP delivery. It receives an `HtmlGenerator` and a production bundle directory.

```php
use Brace\SpaServe\SpaStaticFileServerMw;

$app->addMiddleware(new SpaStaticFileServerMw(
    bundleDir: __DIR__ . '/../frontend/dist',
    html: $html,
    mount: '/admin',
));
```

The mode is derived from the HTML generator:

- `ViteAutoHtml::development(...)`: SPA-Surf always returns the generated SPA shell. Vite is expected to serve `/@vite/client`, source modules and related development assets in front of PHP.
- `ViteAutoHtml::production(...)` or a normal `HtmlGenerator`: existing files below `bundleDir` are served directly. Every other mounted URL falls back to the generated SPA shell.

A complete environment-dependent setup can therefore stay very small:

```php
use Brace\Core\EnvironmentType;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;

$isDevelopment = $app->environmentType === EnvironmentType::DEVELOPMENT;

$html = $isDevelopment
    ? ViteAutoHtml::development(
        entrypoint: '/src/main.ts',
        basePath: '/admin',
        meta: ['spa-api-base-url' => '/api'],
        startElement: 'admin-app',
    )
    : ViteAutoHtml::production(
        basePath: '/admin',
        meta: ['spa-api-base-url' => '/api'],
        css: ['assets/app.css'],
        javascript: ['assets/app.js'],
        startElement: 'admin-app',
    );

$app->addMiddleware(new SpaStaticFileServerMw(
    bundleDir: __DIR__ . '/../frontend/dist',
    html: $html,
    mount: '/admin',
));
```

## Vite proxy in development

Vite should be the public development server and proxy backend/navigation requests to Brace. The exact rules belong to the frontend project.

Example:

```ts
// vite.config.ts
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

SPA-Surf itself never starts Vite and never performs a frontend build.

## Runtime API base URL

Deployment-specific API paths belong in the generated HTML rather than in generated TypeScript.

```php
$html = ViteAutoHtml::production(
    basePath: '/admin',
    meta: [
        'spa-api-base-url' => '/backend/api',
    ],
    javascript: ['assets/app.js'],
);
```

The frontend can read it at runtime:

```ts
import { createAPI } from './generated-api';

const apiBaseUrl = document
  .querySelector<HTMLMetaElement>('meta[name="spa-api-base-url"]')
  ?.content;

export const API = createAPI({
  baseUrl: apiBaseUrl || undefined,
});
```

This keeps deployment-specific hosts and prefixes out of the generated TypeScript file.

## MIME types

Static-file MIME resolution lives in `Brace\SpaServe\Tools\MimeMap` rather than in the SPA middleware.

```php
use Brace\SpaServe\Tools\MimeMap;

$contentType = MimeMap::fromExtension('css');
// text/css; charset=utf-8
```

Unknown extensions return `application/octet-stream`.

## TypeScript API generation

`Brace\SpaServe\Codegen\TypeScriptApiStubModule` uses `phore/schema` to inspect registered PHP callbacks and generates types/routes for `@trunkjs/api-stub`.

```php
use Brace\SpaServe\Codegen\TypeScriptApiStubModule;

$api = new TypeScriptApiStubModule(
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

The generator derives callback parameter/return schemas through `phore/schema`, emits TypeScript DTOs plus the small `@trunkjs/api-stub` route table, and provides both `API` and `createAPI(config)` exports.

The target file may be checked on every application load. `GeneratedFileWriter` compares the generated content with the current file and only performs an actual write when content changed. This keeps timestamps stable and avoids unnecessary frontend rebuild/reload activity.
