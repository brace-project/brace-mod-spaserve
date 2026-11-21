# brace-mod-spaserve

Brace serves the SPA shell and built assets. In development, Vite sits in front of Brace, serves TypeScript/CSS and HMR, and forwards API requests and navigation to Brace. In production, deploy the frontend `assets/` directory, including its generated `dist/` subdirectory, alongside Brace.

## Reference application

[brace-example-spa-prolit](https://github.com/brace-project/brace-example-spa-prolit) is the reference application for integrating this package with Brace, Vite and TrunkJS/Prolit. Use it as a complete application example, including backend controllers, SPA configuration and API-stub generation through Vite.

## Development and production

The application uses one HTML configuration in both modes:

```php
use Brace\Core\Base\ExceptionHandlerMiddleware;
use Brace\Core\Base\JsonReturnFormatter;
use Brace\Core\Base\NotFoundMiddleware;
use Brace\Router\RouterDispatchMiddleware;
use Brace\Router\RouterEvalMiddleware;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;

$app->setPipe([
    new ExceptionHandlerMiddleware(),
    new SpaStaticFileServerMw(
        rootDir: __DIR__ . '/frontend',
        html: new ViteAutoHtml(
            css: ['assets/dist/app.css'],
            javascript: ['assets/dist/app.js'],
            devEntrypoint: '/src/main.ts',
            startHtml: '<demo-app><main id="content"></main></demo-app>',
        ),
        mount: '/e',
        excludePaths: ['/api'],
    ),
    new RouterEvalMiddleware(),
    // Insert the application's API authentication middleware here.
    new RouterDispatchMiddleware([new JsonReturnFormatter($app)]),
    new NotFoundMiddleware(),
]);
```

Instantiate `SpaStaticFileServerMw` directly in the application's middleware
pipeline. Do not register it as a DI service, retrieve it with `$app->get()`,
wrap it in a path-dispatch callback, or invoke it from a SPA controller.
`setPipe()` binds the Brace application automatically; do not call `_setApp()`
manually. With `ViteAutoHtml::development` left unset, the middleware inherits
the Brace environment.

SPA-Serve owns shell delivery and public assets. Configure URL responsibility
through `mount`, `excludePaths` and optionally `spaRoutes`, not through duplicate
router routes. SPA-Serve normally serves the shell and assets without requiring
authentication so the SPA can load and perform client-side authentication, for
example using OpenID Connect or OAuth 2.0 Authorization Code with PKCE. The SPA
initiates the identity-provider flow and processes its callback; SPA-Serve does
not implement or gate that flow. API endpoints independently validate access
tokens and enforce authorization for protected data and actions.
Place API authentication before the API dispatcher; placing SPA-Serve before it
makes the shell and assets public, not the API. Only if shell access itself must
be restricted should additional access control be placed before SPA-Serve.

`SpaStaticFileServerMw` uses `BraceApp::$appBasePath` as its mount by default in development and production and passes the application URLs to `ViteAutoHtml`. Vite forwards navigation paths unchanged, so the application base path remains effective during development. Requests outside the SPA mount and the configured public asset/Vite URL areas are passed unchanged to the next middleware. Pass `mount` explicitly to override the SPA mount. The `rootDir` is the frontend directory. `assetDir` defaults to `assets` and
`bundleDir` defaults to `dist`, giving `rootDir/assets/dist`. Both names must be
clean relative directory paths. Both environments serve public files below `rootDir/assets`
via URLs such as `/assets/images/logo.svg` and `/assets/dist/app.js` relative
to the application's URL prefix, independently of an explicit SPA mount. Existing
SPA-mount-relative asset URLs remain supported for compatibility. In development, use the demo's single negative-match proxy rule: Vite handles
its internal URLs, source modules, stylesheets and HMR; other application
requests are forwarded unchanged to Brace. Do not add separate `/api` or
`/assets` proxy rules that override that selection. Production assets are
served by Brace without Vite. The asset directory must exist
in both environments; `src/` and other frontend paths remain private. The build
directory must exist in production, but not during development.

The generated HTML sets a document base from the effective SPA mount. Without
an application prefix the HTML contains `<base href="/">`; for `mount: '/demo'`
it contains `<base href="/demo/">`. The middleware derives the base from its
effective `mount` for every `HtmlGenerator`, including plain HTML generators.
`HtmlGenerator` defaults `baseHref` to `/` when used independently, but a
middleware-bound generator always receives the normalized mount automatically.
The base element is emitted immediately after the character-set declaration,
before stylesheets and scripts, and its attribute is HTML-escaped.

The generated HTML exposes two independent frontend settings:

```html
<base href="/demo/">
<meta name="app-base-path" content="/demo/">
<meta name="api-base-url" content="https://api.example.test/v1">
```

The HTML `<base>` controls how browsers resolve relative asset URLs, links,
forms and fragment references. For example, `assets/dist/app.js` resolves to
`/demo/assets/dist/app.js` even on `/demo/users/123`, while an absolute
`/api/users` URL remains unchanged. Relative links such as `settings` resolve
to `/demo/settings`, and `#section` targets the base document rather than
necessarily the current nested route. Client-side routers must still handle
the SPA mount, History API navigation and their own route patterns. Use
absolute or router-managed links when the destination must not be base-relative.

`app-base-path` controls frontend routing, navigation and links. `api-base-url` controls API requests and may point to a different path or server. When `BraceApp::$apiBaseUrl` is omitted, it defaults to the application base path. An explicit API client configuration still has the highest priority.

Run Brace on port 80 and Vite on port 4000; open the **Vite** URL in the browser. The demo sets `strictPort: true`, `host: "0.0.0.0"`, `allowedHosts: true` and `hmr: true`, with proxy target `http://127.0.0.1:80`, `changeOrigin: false` and `autoRewrite: true`. This development server accepts all hostnames and listens on all interfaces; expose it only within a trusted development environment. The example in [`examples/demo`](examples/demo) shows its Vite configuration, source entrypoint and `npm run dev` / `npm run build` scripts. Configure Vite with `outDir: 'assets/dist'`, `entryFileNames: 'app.js'`,
`chunkFileNames: '[name]-[hash].js'`, and `assetFileNames: 'app.css'` for CSS.
The middleware does not configure Vite automatically. The emitted paths are
`assets/dist/app.js` and `assets/dist/app.css`. In development the bundle directory need not exist. In production it must exist before Brace starts. Backend routes under `excludePaths` continue to the next middleware, matching an exact path or a slash-delimited child path. Excludes starting with `/` are absolute URL paths (for example `/api` stays `/api` even with SPA mount `/e`); excludes without a leading slash are relative to the SPA mount (for example `api` becomes `/e/api`). When migrating a mount-relative exclusion such as `/api` under `/demo`, use `api` instead. Requests outside SPA-Serve's URL areas are also delegated, so the middleware can be placed directly before backend routing without an application-side path callback. Missing asset paths return 404; navigation paths return the SPA shell.

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

## SPA HTML routes

By default, `spaRoutes: null` lets every valid navigation path inside the SPA
mount receive the HTML shell (current behavior). Optionally restrict this
to route patterns relative to the effective `mount`:

```php
new SpaStaticFileServerMw(
    rootDir: __DIR__ . '/frontend',
    html: new ViteAutoHtml(),
    mount: '/portal',
    spaRoutes: ['/', '/app/**', '/users/*/edit', '/login'],
);
```

Patterns are segment-based: `/` matches only the SPA root; `/**` matches
every route; `/app` matches exactly `/app`; `/app/*` matches one nonempty
segment below `/app`; and `/app/**` matches `/app` plus any number of nested
segments. `/users/*/edit` matches `/users/123/edit`. A trailing slash on
a route or pattern does not affect matching. With `mount: '/portal'`, the
pattern `/app/**` applies to `/portal/app` and `/portal/app/profile`.
`spaRoutes: []` disables all HTML fallback, whereas `null` (the default)
allows all routes. Unsupported partial-segment wildcards, malformed paths
and traversal segments in configured patterns raise `InvalidArgumentException`.

Only **HTML fallback** is controlled: existing public static files are still
served; missing assets keep their usual 404, recognized Vite requests keep their
development diagnostics, and malformed/private requests retain the existing
rejection policy. If a valid navigation path does not match any `spaRoutes`,
SPA-Serve delegates the original request to the next middleware rather than
serving HTML. `excludePaths` continues to bypass SPA-Serve earlier in the
request flow. These patterns select HTML-routing responsibility, **not access
authorization**.

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

Except for the development diagnostics above, missing files under the configured `assetDir` and
missing paths with a file extension return HTTP 404, even when HTML is accepted.
A missing stylesheet at
`/demo/assets/dist/app.css` receives this short plain-text response:

```text
File not Found (SPA-Serve Asset Path: /demo/assets/dist/). CSS requested.
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

The registered route callback's parameter and return types (including PHPDoc collection types) are read with `phore/schema`. Router parameters such as `:userId` become path parameters; query parameters require explicit `BraceQuery` declarations. A parameter named `body` supplies the request body for POST/PUT/PATCH routes. Public DTO properties become TypeScript interfaces. `GeneratedFileWriter` compares the generated content and only replaces the target file when it changed, so repeated generation does not trigger Vite HMR or reloads unnecessarily.

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

### Explicit query bindings

Query parameters are declared using `Brace\Router\Attributes\BraceQuery` on
controller methods or parameters. The generator uses the router's shared
`QueryParameterResolver`: query aliases become wire keys, PHP defaults become
optional TypeScript properties, and unbound method declarations default to string
unless `type` is specified. Method-only declarations can provide `default`.

Unannotated non-path parameters are no longer implicitly classified as query
parameters. Annotate existing query bindings when migrating. A `$query` parameter
permits additional keys and generates an open string index signature alongside
the declared properties. At runtime, `QueryParamsValidator` validates declared
keys and rejects unknown keys unless `$query` is present. This requires a router
version providing `BraceQuery`, `QueryParameterResolver` and `QueryParamsValidator`.

Run the focused rendering check with `php tests/QueryParameterCodegenTest.php`
from the package checkout inside the application's Composer vendor tree.

`API` uses the same origin by default. The generated types provide compile-time guidance; validation of HTTP payloads remains the application's responsibility. Duplicate route names and conflicting DTO short names are rejected during generation.
