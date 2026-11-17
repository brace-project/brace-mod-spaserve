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

The middleware rejects unclean, once-decoded incoming URL paths with
`phore_uri($path)->isClean()`; it never repairs user-supplied traversal.
This API comes from [phore/filesystem PR #5](https://github.com/phore/phore-filesystem/pull/5).
Trusted configuration URLs use `formatUri(trailingSlash: false)` to clean and
format paths in one call. `PhoreUri::rel()->formatUri(leadingSlash: true)`
produces API route paths without manual dot/root or slash concatenation.
A trailing slash is explicitly preserved where route semantics require it.
Resolved filesystem boundaries still use `PhoreUri::rel()`; public-asset
policy and HTTP encoding checks remain separate.

## Development proxy diagnostics

Vite must handle its development client, internal modules and source entrypoint.
If these requests reach SPA-Serve in development, the middleware returns HTTP
500 with this fixed plain-text diagnostic:

```text
Vite development resource reached SPA-Serve. Check server.proxy in vite.config and open the Vite development URL.
```

This indicates unexpected development routing: check the URL/port opened in the
browser and `server.proxy` in `vite.config.ts` or `vite.config.js`. A catch-all
proxy can incorrectly forward Vite's own requests to PHP. Requests matched by a
[Vite proxy rule](https://vite.dev/config/server-options#server-proxy) are not
subsequently transformed by Vite. [HTTP 500][http-500] describes the detected
configuration problem; SPA-Serve does not perform an upstream request that could
justify 502.

The reserved paths are `/@vite/client`, `/@vite/env`, `/@id/` and `/@fs/`, both at
the domain root and under the effective SPA mount. The default HTML generator
emits `/@vite/client` and `/src/main.ts` at the domain root, even with a `/demo`
mount. Custom local `devViteClient` and `devEntrypoint` URLs are also recognized
in development: relative URLs use the mount, while paths starting with `/` retain
their location. Configured local path components are normalized with
`phore_uri($path)->clean()->getUri()`: literal `.` and `..` segments are resolved,
and redundant or trailing slashes are removed. Percent-encoded dot segments are
not resolved by `clean()`; incoming traversal requests remain rejected. Query
strings and fragments are ignored for the diagnostic route comparison. Full external URLs and protocol-relative URLs
do not reserve a corresponding local path.

Production returns a generic 404 for the reserved Vite paths. Ordinary source
paths and custom development configuration do not introduce production routing
restrictions. Excluded API routes keep their precedence; malformed or private
paths still receive 404 and unsupported methods 405. Diagnostic responses have
`nosniff` and `Cache-Control: no-store`; HEAD has no body. No request URL, query,
header value or local filesystem path is reflected in the Vite diagnostic.

[http-500]: https://www.rfc-editor.org/rfc/rfc9110.html#section-15.6.1

## Missing assets and HTML fallback

Except for the development diagnostics above, missing files under `assets/` and
missing paths with a file extension return HTTP 404, even when HTML is accepted.
A missing stylesheet at
`/demo/assets/app.css` receives this short plain-text response:

```text
File not Found (SPA-Serve Asset Path: /demo/assets/). CSS requested.
```

JavaScript requests receive `JavaScript requested.` instead. The directory is
the public URL directory, with its segments percent-encoded; the response never
exposes the server's local bundle path. Rejected private or malformed paths keep
generic diagnostics. Error responses use `nosniff` and `Cache-Control: no-store`;
HEAD responses have no body. Bundle/read/render failures remain HTTP 500.

For extensionless client-side routes, the SPA shell is available only when
`Accept` permits `text/html; charset=utf-8`. The most specific matching media
range determines its quality: `text/html;q=0, */*;q=1` rejects HTML. An absent
`Accept` header accepts any media type; a present empty header accepts none.
Malformed or ambiguous headers fail with 404 instead of generating the shell.

The middleware also checks resource intent. `Sec-Fetch-Dest: script`, `style`
and other resource destinations reject fallback even with `Accept: */*`.
Only absent metadata and the tokens `empty`, `document`, `frame` and `iframe`
permit fallback; unknown or malformed destination values are rejected by this
module's conservative policy. An explicit CSS/JavaScript preference also blocks
fallback when its quality is higher than HTML's, or tied with an HTML wildcard.
An equally preferred explicit `text/html` range remains suitable for navigation.

Generated HTML and these 404 responses include `Vary: Accept, Sec-Fetch-Dest`.
Public files outside reserved Vite paths and excluded API routes retain their
own delivery behavior.
An extensionless request with only `Accept: */*` and no resource destination is
indistinguishable from a compatible navigation request and can receive HTML;
asset clients should identify their media type or use an asset URL.

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
));
```

The module makes Brace Command available when necessary and registers `spa-api-build` itself. The configured stub can therefore be generated through the normal Brace CLI without an additional build PHP file:

```bash
vendor/bin/brace spa-api-build
```

Stub generation is driven by Vite through the `spa-api-build` command: on development-server startup, backend hot updates and production builds. Module registration and HTTP requests never generate stubs; there is no on-request generation option. Run the command above manually when needed. Generated output includes named routes, their typed parameters and response DTOs; unnamed routes are ignored. See the Vite workflow below.

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
