# brace-mod-spaserve

Brace serves the SPA shell and built assets. In development, Vite sits in front of Brace, serves TypeScript/CSS and HMR, and forwards API requests and navigation to Brace. In production, deploy Vite's `dist` directory alongside Brace.

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
        css: ['/assets/app.css'],
        javascript: ['/assets/app.js'],
        devEntrypoint: '/src/main.ts',
        startHtml: '<demo-app><main id="content"></main></demo-app>',
    ),
    excludePaths: ['/api'],
));
```

Run Brace on port 8080 and Vite on port 4000; open the **Vite** URL in the browser. The example in [`examples/demo`](examples/demo) shows its Vite configuration, source entrypoint and `npm run dev` / `npm run build` scripts. The build emits `dist/assets/app.js` and `dist/assets/app.css`; pass the actual asset paths to `ViteAutoHtml` if your build differs. In development the bundle directory need not exist. In production it must exist before Brace starts. Backend routes under `excludePaths` continue to the next middleware. Missing asset paths return 404; navigation paths return the SPA shell.

`ViteAutoHtml` also supports `basePath`, `additionalCss`, `additionalJavascript`, `meta`, `title` and `startHtml`. `startHtml` is trusted application HTML and is inserted unchanged at the beginning of `<body>`, before the generated module scripts. It can therefore contain complete nested startup markup instead of describing only one element. It must not contain untrusted user input. ViteAutoHtml emits `/@vite/client` and `devEntrypoint` in development, and the configured CSS/JavaScript bundle in production. It does not inspect Vite manifests. Avoid the legacy `EsbuildLoader`, `HttpProxy` and LiveReload setup for new applications.

## Typed API stub

`TypeScriptApiStubModule` handles the complete Brace integration for API-stub generation. Configure the target file and routes, then add the module to the application:

```php
use Brace\SpaServe\Codegen\TypeScriptApiStubModule;

$callback = [UserController::class, 'get'];
$app->router->on('GET@/api/users/:userId', $callback);

$api = new TypeScriptApiStubModule(
    targetFile: __DIR__ . '/frontend/src/generated-api.ts',
    autoGenerateInDevelopment: true,
);
$api->route(
    name: 'User.Get',
    path: '/api/users/{userId}',
    methods: 'GET',
    callback: $callback,
);

$app->addModule($api);
```

The module makes Brace Command available when necessary and registers `spa-api-build` itself. The configured stub can therefore be generated through the normal Brace CLI without an additional build PHP file:

```bash
vendor/bin/brace spa-api-build
```

With `autoGenerateInDevelopment: true`, generation also runs during normal application bootstrap when `$app->environmentType === EnvironmentType::DEVELOPMENT`. Set the option to `false` to disable this automatic path. Production and test environments never auto-generate, and CLI startup does not generate until `spa-api-build` is actually executed.

The PHP callback's parameter and return types (including PHPDoc collection types) are read with `phore/schema`. A parameter named in `{braces}` becomes a path parameter, other parameters become query parameters, and `bodyParameter: 'data'` marks a JSON request body. Public DTO properties become TypeScript interfaces. `GeneratedFileWriter` compares the generated content and only replaces the target file when it changed, so repeated generation does not trigger Vite HMR or reloads unnecessarily.

### Vite integration

For Vite, use the generic `vite-plugin-run` package instead of implementing a project-specific plugin. It can run the Brace command when Vite starts and before a production build:

```ts
import { defineConfig } from 'vite';
import { run } from 'vite-plugin-run';

export default defineConfig({
  plugins: [
    run({
      name: 'Brace API stub',
      run: ['vendor/bin/brace', 'spa-api-build'],
      startup: true,
      build: true,
    }),
  ],
});
```

No Brace-specific Vite plugin or separate API build entrypoint is required. If the Vite project is located in a subdirectory, adjust only the relative path to `vendor/bin/brace`; the output path remains configured once in `TypeScriptApiStubModule`.

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
