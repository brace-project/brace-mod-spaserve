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
        startElement: 'demo-app',
    ),
    excludePaths: ['/api'],
));
```

Run Brace on port 8080 and Vite on port 4000; open the **Vite** URL in the browser. The example in [`examples/demo`](examples/demo) shows its Vite configuration, source entrypoint and `npm run dev` / `npm run build` scripts. The build emits `dist/assets/app.js` and `dist/assets/app.css`; pass the actual asset paths to `ViteAutoHtml` if your build differs. In development the bundle directory need not exist. In production it must exist before Brace starts. Backend routes under `excludePaths` continue to the next middleware. Missing asset paths return 404; navigation paths return the SPA shell.

`ViteAutoHtml` also supports `basePath`, `additionalCss`, `additionalJavascript`, `meta`, `title` and `startElement`. It emits `/@vite/client` and `devEntrypoint` in development, and the configured CSS/JavaScript bundle in production. It does not inspect Vite manifests. Avoid the legacy `EsbuildLoader`, `HttpProxy` and LiveReload setup for new applications.

## Typed API stub

Register the actual endpoint with Brace and describe the same callback to the generator. `route()` records the client contract; it does **not** register an HTTP route or enforce response validation at runtime.

```php
use Brace\SpaServe\Codegen\TypeScriptApiStubModule;

$callback = [UserController::class, 'get'];
$app->router->on('GET@/api/users/:userId', $callback);

$api = new TypeScriptApiStubModule(
    targetFile: __DIR__ . '/frontend/src/generated-api.ts',
);
$api->route(
    name: 'User.Get',
    path: '/api/users/{userId}',
    methods: 'GET',
    callback: $callback,
);
$api->load();
```

The PHP callback's parameter and return types (including PHPDoc collection types) are read with `phore/schema`. A parameter named in `{braces}` becomes a path parameter, other parameters become query parameters, and `bodyParameter: 'data'` marks a JSON request body. Public DTO properties become TypeScript interfaces. If the generated content has not changed, the writer leaves the file untouched, so Vite does not rebuild it on every PHP request. Generate before `vite build` and whenever the API signature changes during development.

The generated source imports `createApi` and `ApiRoute` from `@trunkjs/api-stub`. It contains nested TypeScript route names and a compact flat route table for the runtime:

```ts
import { API, createAPI } from './generated-api';

const user = await API.User.Get.request({
  params: { userId: 42 },
  query: { locale: 'de' },
});
// user is typed as User.

const remoteAPI = createAPI({ baseUrl: 'https://example.test' });
```

`API` uses the same origin by default. The generated types provide compile-time guidance; validation of HTTP payloads remains the application's responsibility. Duplicate route names and conflicting DTO short names are rejected during generation.
