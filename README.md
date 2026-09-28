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

The stub module is intended to be part of the Brace application. Register the actual endpoint with Brace, describe the same callback to the stub module, then add that module to the app. `route()` records the client contract; it does **not** register an HTTP route or enforce response validation at runtime.

```php
use Brace\Command\CommandModule;
use Brace\SpaServe\Codegen\TypeScriptApiStubModule;

$app->addModule(new CommandModule());

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

When added to Brace, `TypeScriptApiStubModule` always registers the `spa-api-build` command. With `autoGenerateInDevelopment: true`, it also generates when the module is registered and `$app->environmentType === EnvironmentType::DEVELOPMENT`. Setting the flag to `false` disables that automatic path. Production and test environments never auto-generate, while the explicit command remains available for builds.

The PHP callback's parameter and return types (including PHPDoc collection types) are read with `phore/schema`. A parameter named in `{braces}` becomes a path parameter, other parameters become query parameters, and `bodyParameter: 'data'` marks a JSON request body. Public DTO properties become TypeScript interfaces. `GeneratedFileWriter` compares the generated content and only replaces the target file when it changed, so repeated development generation does not trigger Vite HMR or reloads unnecessarily.

### Lightweight build command

The normal `brace` CLI loads the complete default application through `AppLoader::loadApp()`. If code generation should not bootstrap HTTP middleware, controllers and other application configuration, use a small build entry like [`examples/demo/build-api.php`](examples/demo/build-api.php). It creates only a minimal `BraceApp`, adds `CommandModule`, loads the shared API contract, adds the stub module and runs its `spa-api-build` command.

The API contract itself can live in a separate file such as [`examples/demo/api-stub.php`](examples/demo/api-stub.php), so the normal application and the lightweight build command use the same routes and callbacks without loading the complete web application.

### Vite integration

The demo runs the lightweight PHP build command from a small Vite plugin:

```ts
const braceApiStub: Plugin = {
  name: 'brace-api-stub',
  buildStart() {
    execFileSync('php', ['build-api.php'], {
      cwd: demoDir,
      stdio: 'inherit',
    });
  },
};
```

Vite invokes `buildStart` when the development server starts and before a production build. During development, later PHP API changes are picked up by the Brace-side development auto-generation on the next application load. In both cases the generated TypeScript file is only rewritten when its content actually changes.

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
