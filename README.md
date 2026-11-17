# brace-mod-spaserve

Brace serves the SPA shell and built assets. In development, Vite sits in front of Brace, serves TypeScript/CSS and HMR, and forwards API requests and navigation to Brace. In production, deploy Vite's `dist` directory alongside Brace.

## Reference application

[brace-example-spa-prolit](https://github.com/brace-project/brace-example-spa-prolit) is the reference application for integrating this package with Brace, Vite and TrunkJS/Prolit. Use it as a complete application example, including backend controllers, SPA configuration and API-stub generation through Vite.

## Development and production

The application uses one HTML configuration in both modes:

```php
use Brace\Core\EnvironmentType;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;

$app->addMiddleware(new SpaStaticFileServerMw(
    bundleDir: __DIR__ . '/frontend/dist',
    html: new ViteAutoHtml(
        development: $app->environmentType === EnvironmentType::DEVELOPMENT,
        css: ['assets/app.css'],
        javascript: ['assets/app.js'],
        devEntrypoint: '/src/main.ts',
        startHtml: '<demo-app><main id="content"></main></demo-app>',
    ),
    excludePaths: ['/api'],
));
```

`SpaStaticFileServerMw` uses `BraceApp::$appBasePath` as its mount by default in development and production and passes the application URLs to `ViteAutoHtml`. Vite forwards navigation paths unchanged, so the application base path remains effective during development. Requests outside the configured path receive a descriptive 404 response. Pass `mount` explicitly to override the SPA mount. Relative asset paths such as `assets/app.js` are resolved below the effective application base path.

The generated HTML exposes two independent frontend settings:

```html
<meta name="app-base-path" content="/demo/">
<meta name="api-base-url" content="https://api.example.test/v1">
```

`app-base-path` controls frontend routing, navigation and links. `api-base-url` controls API requests and may point to a different path or server. When `BraceApp::$apiBaseUrl` is omitted, it defaults to the application base path. An explicit API client configuration still has the highest priority.

Run Brace on port 8080 and Vite on port 4000; open the **Vite** URL in the browser. The example in [`examples/demo`](examples/demo) shows its Vite configuration, source entrypoint and `npm run dev` / `npm run build` scripts. The build emits `dist/assets/app.js` and `dist/assets/app.css`; pass the actual asset paths to `ViteAutoHtml` if your build differs. In development the bundle directory need not exist. In production it must exist before Brace starts. Backend routes under `excludePaths` continue to the next middleware. Missing asset paths return 404; navigation paths return the SPA shell.

`ViteAutoHtml` also supports `appBasePath`, `apiBaseUrl`, `additionalCss`, `additionalJavascript`, `meta`, `title` and `startHtml`. `startHtml` is trusted application HTML and is inserted unchanged at the beginning of `<body>`, before the generated module scripts. It can therefore contain complete nested startup markup instead of describing only one element. It must not contain untrusted user input. ViteAutoHtml emits `/@vite/client` and `devEntrypoint` in development, and the configured CSS/JavaScript bundle in production. It does not inspect Vite manifests. Avoid the legacy `EsbuildLoader`, `HttpProxy` and LiveReload setup for new applications.

## Typed API stub

`TypeScriptApiGeneratorModule` handles the complete Brace integration for API-stub generation. It reads every named route already registered on the Brace router; unnamed routes are ignored:

```php
use Brace\SpaServe\Codegen\TypeScriptApiGeneratorModule;

$app->router->on(
    'GET@/api/users/:userId',
    [UserController::class, 'get'],
    [],
    'User.Get',
);

$app->addModule(new TypeScriptApiGeneratorModule(
    targetFile: __DIR__ . '/frontend/src/generated-api.ts',
    autoGenerateInDevelopment: false, // Default: rebuild explicitly or through Vite.
));
```

The module makes Brace Command available when necessary and registers `spa-api-build` itself. The configured stub can therefore be generated through the normal Brace CLI without an additional build PHP file:

```bash
vendor/bin/brace spa-api-build
```

`autoGenerateInDevelopment` defaults to `false`: normal application requests do not rebuild the stub. Rebuild explicitly with `spa-api-build` or use the Vite workflow below. Setting `autoGenerateInDevelopment: true` opts back into generation during normal application bootstrap when `$app->environmentType === EnvironmentType::DEVELOPMENT`. Production and test environments never auto-generate, and CLI startup does not generate until `spa-api-build` is actually executed.

The registered route callback's parameter and return types (including PHPDoc collection types) are read with `phore/schema`. Router parameters such as `:userId` become path parameters, while other callback parameters become query parameters. Public DTO properties become TypeScript interfaces. `GeneratedFileWriter` compares the generated content and only replaces the target file when it changed, so repeated generation does not trigger Vite HMR or reloads unnecessarily.

### Vite integration

Install `vite-plugin-run` as a development dependency (`npm install -D vite-plugin-run`). Run Vite from the application repository root, where `vendor/bin/brace` and the Brace bootstrap are located. For a frontend root of `app.fe/` and backend sources under `app.be/`, configure the root `vite.config.ts` as follows:

```ts
import { defineConfig } from 'vite';
import { run } from 'vite-plugin-run';
import { fileURLToPath } from 'node:url';

export default defineConfig({
  root: 'app.fe',
  plugins: [
    {
      name: 'watch-backend-directory',
      apply: 'serve',
      configureServer(server) {
        // app.be is outside Vite's app.fe root.
        server.watcher.add(fileURLToPath(new URL('./app.be', import.meta.url)));
      },
    },
    run({
      silent: false,
      input: [{
        name: 'Brace API stub',
        run: ['vendor/bin/brace', 'spa-api-build'],
        pattern: '../app.be/**/*', // Relative to Vite's root.
        debounce: 200,
        startup: false,
        build: false,
      }],
    }),
  ],
});
```

Generate the initial stub before starting Vite, and generate it again before production builds:

```bash
vendor/bin/brace spa-api-build && npm run dev
# Production / CI:
vendor/bin/brace spa-api-build && npm run build
```

These commands assume that the application's `dev` and `build` scripts invoke `vite` and `vite build`. Explicit sequencing ensures generation finishes before Vite reads the stub; `vite-plugin-run` 0.8 does not await startup commands. While the dev server runs, saving existing backend files triggers generation with a 200 ms debounce. Its hot-update hook does not reliably cover file creation or deletion, and commands are not serialized across separate changes during a running build; rebuild manually after structural changes. Generated output under `app.fe/` does not match the backend pattern, preventing a rebuild loop.

No Brace-specific Vite plugin or separate PHP build entrypoint is required. Adjust the backend watch directory and pattern for other layouts; command paths are relative to the working directory, patterns to Vite's root. The output path remains configured in `TypeScriptApiGeneratorModule`.

The generated source imports `createApi` and `ApiRoute` from `@trunkjs/api-stub`. It contains nested TypeScript route names and a compact flat route table for the runtime:

```ts
import { API, createAPI } from './generated-api';

const user = await API.User.Get.request({
  params: { userId: 42 },
  query: { locale: 'de' },
});
// user is typed from the PHP callback return value.

const remoteAPI = createAPI({ baseUrl: 'https://example.test' });
```

`API` uses the same origin by default. The generated types provide compile-time guidance; validation of HTTP payloads remains the application's responsibility. Duplicate route names and conflicting DTO short names are rejected during generation.
