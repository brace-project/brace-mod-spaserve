<?php

declare(strict_types=1);

namespace Brace\SpaServe;

use Brace\Core\Base\BraceAbstractMiddleware;
use Brace\Core\BraceApp;
use Brace\Core\EnvironmentType;
use Brace\SpaServe\Html\HtmlGenerator;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\Tools\MimeMap;
use Phore\FileSystem\Exception\FilesystemException;
use Phore\FileSystem\Exception\FilesystemPolicyViolationException;
use Phore\FileSystem\Exception\PathOutOfBoundsException;
use Phore\FileSystem\PhoreDirectory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Serves the SPA shell and, in production, the files emitted by Vite. */
final class SpaStaticFileServerMw extends BraceAbstractMiddleware
{
    private const MAX_PATH_BYTES = 8192;
    private const MAX_ACCEPT_BYTES = 8192;
    private const MAX_ACCEPT_RANGES = 64;
    private const FALLBACK_VARY = 'Accept, Sec-Fetch-Dest';
    private const VITE_PROXY_ERROR = 'Vite development resource reached SPA-Serve. '
        . 'Check server.proxy in vite.config and open the Vite development URL.';

    private ?PhoreDirectory $assetDirectory = null;
    private ?PhoreDirectory $bundleDirectory = null;
    private ?string $assetRoot = null;
    private bool $development = false;
    private PhoreDirectory|string $configuredRootDir;
    private string $configuredAssetDir;
    private string $configuredBundleDir;
    private ?bool $configuredDevelopment;
    private ?string $configuredMount;
    public string $mount;

    /**
     * Configure SPA delivery after the application's API middleware.
     *
     * Development only serves the HTML shell; Vite serves source, assets and HMR.
     * Production serves files only from rootDir/assetDir and requires
     * rootDir/assetDir/bundleDir for the generated build.
     *
     * @param PhoreDirectory|string $rootDir Frontend root directory.
     * @param string $assetDir Public files folder relative to rootDir.
     * @param string $bundleDir Build folder relative to assetDir.
     * @param HtmlGenerator $html HTML shell for client side routes.
     * @param string $mount SPA URL prefix.
     * @param list<string> $excludePaths Backend paths that must reach the next handler.
     * @param list<string>|null $spaRoutes SPA HTML route patterns relative to mount; null permits all.
     * @throws \RuntimeException If the production bundle directory is missing.
     * @see ViteAutoHtml
     * @example new SpaStaticFileServerMw(__DIR__ . '/app.fe', new ViteAutoHtml(css: ['assets/dist/app.css']));
     */
    public function __construct(
        PhoreDirectory|string $rootDir,
        public HtmlGenerator $html,
        string $assetDir = 'assets',
        string $bundleDir = 'dist',
        ?string $mount = null,
        public array $excludePaths = ['/api'],
        public string $apiMountPath = '/api',
        public ?array $spaRoutes = null,
    ) {
        foreach (['assetDir' => $assetDir, 'bundleDir' => $bundleDir] as $name => $directory) {
            foreach (explode('/', $directory) as $segment) {
                if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/D', $segment) !== 1 || str_ends_with($segment, '.')) {
                    throw new \InvalidArgumentException("$name must be a clean relative directory path.");
                }
            }
        }
        if ($this->spaRoutes !== null) {
            foreach ($this->spaRoutes as $pattern) {
                if (
                    !is_string($pattern)
                    || !str_starts_with($pattern, '/')
                    || !phore_uri($pattern)->isClean()
                    || strpbrk($pattern, '?#') !== false
                ) {
                    throw new \InvalidArgumentException('SPA route patterns must be clean absolute URL paths.');
                }
                foreach (explode('/', trim($pattern, '/')) as $segment) {
                    if (str_contains($segment, '*') && $segment !== '*' && $segment !== '**') {
                        throw new \InvalidArgumentException('SPA route wildcards must occupy a complete segment.');
                    }
                }
            }
        }

        $this->configuredMount = $mount;
        $this->mount = $this->normalizeMount($mount ?? '/');
        $this->configuredRootDir = $rootDir;
        $this->configuredAssetDir = $assetDir;
        $this->configuredBundleDir = $bundleDir;
        $this->configuredDevelopment = $html instanceof ViteAutoHtml ? $html->development : false;
    }

    /**
     * Bind application URLs and the production bundle's filesystem boundary.
     *
     * A missing production bundle fails during application bootstrap. Runtime
     * requests translate filesystem errors into responses without diagnostics.
     * The bundle must be trusted and must not be writable by untrusted actors.
     *
     * @param BraceApp $app Application supplying the response factory and mount.
     * @throws FilesystemException If the production bundle cannot be resolved.
     * @see BraceAbstractMiddleware::_setApp()
     * @example $middleware->_setApp($app);
     */
    public function _setApp(BraceApp $app): void
    {
        parent::_setApp($app);
        $this->mount = $this->normalizeMount($this->configuredMount ?? $app->url_prefix);
        $this->development = $this->configuredDevelopment
            ?? ($app->environmentType === EnvironmentType::DEVELOPMENT);
        // Public files must stay inside the frontend root's asset directory.
        $this->assetDirectory = null;
        $this->bundleDirectory = null;
        $this->assetRoot = null;
        if (!$this->development) {
            $rootDir = phore_dir($this->configuredRootDir)->assertDirectory();
            $root = realpath((string) $rootDir);
            $assetDir = phore_dir((string) $rootDir . '/' . $this->configuredAssetDir)->assertDirectory();
            $assetRoot = realpath((string) $assetDir);
            if ($root === false || $assetRoot === false
                || !str_starts_with($assetRoot, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            ) {
                throw new FilesystemException("Cannot resolve SPA asset directory '$assetDir' within '$rootDir'.");
            }
            $bundleDir = phore_dir($assetRoot . '/' . $this->configuredBundleDir)->assertDirectory();
            $bundleRoot = realpath((string) $bundleDir);
            if ($bundleRoot === false
                || !str_starts_with($bundleRoot, rtrim($assetRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            ) {
                throw new FilesystemException("Cannot resolve SPA bundle directory '$bundleDir' within '$assetDir'.");
            }
            $this->assetRoot = $assetRoot;
            $this->assetDirectory = phore_dir($assetDir, ['rootDir' => $assetRoot]);
            $this->bundleDirectory = phore_dir($bundleDir, ['rootDir' => $assetRoot]);
        }
        if ($this->html instanceof ViteAutoHtml) {
            $this->html->development = $this->development;
            $this->html->setApplicationUrls(
                $this->mount,
                phore_uri($this->mount . '/' . trim($this->apiMountPath, '/'))->formatUri(trailingSlash: false),
            );
        }
        // Der effektive SPA-Mount bestimmt die URL-Basis jedes HTML-Generators.
        $this->html->baseHref = rtrim($this->mount, '/') . '/';
        $this->html->meta['app-env'] = $app->environmentType->value;
    }

    /**
     * Serve a validated GET/HEAD asset or SPA route, or delegate an excluded API.
     *
     * URL decoding precedes every routing and filesystem decision. Invalid or
     * private paths return 404, unsupported methods 405 and operational errors
     * 500. Missing assets name only the encoded public URL directory, never a
     * filesystem path. HTML fallback respects Accept and the fetch destination.
     * Misrouted Vite resources return a development-only proxy diagnostic (500)
     * before file access; reserved Vite endpoints return 404 in production.
     * Excluded requests are passed to the handler unchanged. Unmatched spaRoutes
     * delegate to the next handler after asset and request-policy checks.
     *
     * @param ServerRequestInterface $request Request from the HTTP adapter.
     * @param RequestHandlerInterface $handler Handler for excluded API routes.
     * @return ResponseInterface A module response or the delegated response.
     * @see RequestHandlerInterface::handle()
     * @example $response = $middleware->process($request, $next);
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Auch fehlerhafte Daten eines vorgeschalteten HTTP-Adapters ablehnen.
        $rawPath = $request->getUri()->getPath();
        if (strlen($rawPath) > self::MAX_PATH_BYTES) {
            return $this->errorResponse($request, 414, 'SPA request path is too long.');
        }
        $path = $this->decodeRequestPath($rawPath);
        if ($path === null) {
            return $this->errorResponse($request, 404, 'Invalid or disallowed SPA path.');
        }

        foreach ($this->excludePaths as $excludePath) {
            $excludePath = $this->resolveExcludedPath($excludePath);
            if ($path === $excludePath || str_starts_with($path, $excludePath . '/')) {
                return $handler->handle($request);
            }
        }
        $mountedPath = $this->isMountedPath($path);
        $viteResource = $this->isViteResourcePath($path);
        if (!$mountedPath && !$viteResource) {
            return $this->errorResponse($request, 404, 'Requested path is outside the configured SPA URL prefix.');
        }
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->errorResponse($request, 405, 'SPA delivery only supports GET and HEAD.', ['Allow' => 'GET, HEAD']);
        }

        $relativePath = $mountedPath ? $this->relativePath($path) : ltrim($path, '/');
        if (!$this->isPublicAssetPath($relativePath)) {
            return $this->errorResponse($request, 404, 'SPA asset not found.');
        }
        if ($viteResource) {
            // Vite muss diese Requests selbst bedienen, auch außerhalb des Mounts.
            return $this->development
                ? $this->errorResponse($request, 500, self::VITE_PROXY_ERROR)
                : $this->errorResponse($request, 404, 'SPA asset not found.');
        }

        // Nur Dateien innerhalb des oeffentlichen Asset-Verzeichnisses lesen.
        if (!$this->development) {
            try {
                $this->assetDirectory->assertDirectory();
                $this->bundleDirectory->assertDirectory();
            } catch (FilesystemException) {
                return $this->errorResponse($request, 500, 'Unable to read the SPA bundle.');
            }

            try {
                $assetPrefix = $this->configuredAssetDir . '/';
                if (str_starts_with($relativePath, $assetPrefix)) {
                    $assetPath = substr($relativePath, strlen($assetPrefix));
                    if ($assetPath === '') {
                        return $this->missingAssetResponse($request, $relativePath);
                    }
                    $assetResponse = $this->tryStaticFile($assetPath, $request);
                    if ($assetResponse !== null) {
                        return $assetResponse;
                    }
                }
            } catch (PathOutOfBoundsException | FilesystemPolicyViolationException) {
                return $this->errorResponse($request, 404, 'SPA asset not found.');
            } catch (FilesystemException) {
                return $this->errorResponse($request, 500, 'Unable to read the SPA bundle.');
            }
        }

        // Fehlende Assets dürfen auch bei HTML-Akzeptanz keine SPA-Seite erhalten.
        if ($relativePath === $this->configuredAssetDir || str_starts_with($relativePath, $this->configuredAssetDir . '/') || pathinfo($relativePath, PATHINFO_EXTENSION) !== '') {
            return $this->missingAssetResponse($request, $relativePath);
        }
        if (!$this->matchesSpaRoute($relativePath)) {
            return $handler->handle($request);
        }

        $fallbackRejection = $this->fallbackRejection($request);
        if ($fallbackRejection !== null) {
            return $this->missingAssetResponse($request, $relativePath, $fallbackRejection);
        }

        try {
            $html = $this->html->render();
        } catch (\Throwable) {
            // Fehler im HTML-Generator dürfen keine internen Details ausgeben.
            return $this->errorResponse($request, 500, 'Unable to render the SPA page.');
        }

        return $this->respond(
            $request,
            $html,
            200,
            ['Content-Type' => 'text/html; charset=utf-8', 'Vary' => self::FALLBACK_VARY],
        );
    }

    /**
     * Match SPA HTML routes relative to the mount; '*' matches one segment,
     * '**' matches zero or more segments. Patterns are not authorization rules.
     */
    private function matchesSpaRoute(string $relativePath): bool
    {
        if ($this->spaRoutes === null) {
            return true;
        }

        $path = '/' . trim($relativePath, '/');
        foreach ($this->spaRoutes as $pattern) {
            $pattern = rtrim($pattern, '/') ?: '/';
            if ($pattern === '/') {
                if ($path === '/') {
                    return true;
                }
                continue;
            }

            $regex = '';
            foreach (explode('/', ltrim($pattern, '/')) as $segment) {
                $regex .= match ($segment) {
                    '*' => '/[^/]+',
                    '**' => '(?:/[^/]+)*',
                    default => '/' . preg_quote($segment, '~'),
                };
            }
            if (preg_match('~\\A' . $regex . '\\z~D', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isViteResourcePath(string $path): bool
    {
        // Standard-URLs können global oder unter Vites konfiguriertem Base liegen.
        $candidates = [$path];
        if ($this->mount !== '/' && $this->isMountedPath($path)) {
            $candidates[] = phore_uri($path)->rel($this->mount)->formatUri(leadingSlash: true, trailingSlash: str_ends_with($path, '/'));
        }
        foreach ($candidates as $candidate) {
            // Client/Env exakt, ID/FS nur mit vollständiger Segmentgrenze erkennen.
            if (preg_match('~\A/@(?:vite/(?:client|env)\z|(?:id|fs)(?:/|\z))~', $candidate) === 1) {
                return true;
            }
        }

        if (!$this->development || !($this->html instanceof ViteAutoHtml)) {
            return false;
        }
        foreach ([$this->html->devViteClient, $this->html->devEntrypoint] as $url) {
            if ($url !== null && $this->resolveDevelopmentPath($url) === $path) {
                return true;
            }
        }
        return false;
    }

    private function resolveDevelopmentPath(string $url): ?string
    {
        if (
            $url === ''
            || str_starts_with($url, '//')
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)
            || preg_match('/[\x00-\x1f\x7f]/', $url)
        ) {
            return null;
        }
        // Wie ViteAutoHtml: relative URLs am Mount auflösen, absolute unverändert.
        if (!str_starts_with($url, '/')) {
            $url = rtrim($this->mount, '/') . '/' . $url;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        // Nur konfigurierte URL-Pfade bereinigen; Requests bleiben strikt validiert.
        return $this->decodeRequestPath(phore_uri($path)->formatUri(trailingSlash: false));
    }

    private function tryStaticFile(string $assetPath, ServerRequestInterface $request): ?ResponseInterface
    {
        // Nicht auflösbare Links sind keine auslieferbaren Ressourcen. Fehler
        // beim anschließenden Lesen bleiben davon getrennte Serverfehler.
        try {
            $file = $this->assetDirectory->withSubPath($assetPath);
            if (!$file->exists()) {
                return null;
            }
            if (!$file->isFile()) {
                return $this->missingAssetResponse($request, $this->configuredAssetDir . '/' . $assetPath);
            }
        } catch (FilesystemException) {
            return $this->errorResponse($request, 404, 'SPA asset not found.');
        }

        // Auch das reale Symlink-Ziel muss innerhalb der Asset-Root oeffentlich sein.
        $resolved = realpath((string) $file);
        if ($resolved === false) {
            return $this->errorResponse($request, 500, 'Unable to read the SPA asset.');
        }
        try {
            $resolvedRelative = phore_uri($resolved)->rel($this->assetRoot)->getUri();
        } catch (\InvalidArgumentException) {
            return $this->errorResponse($request, 404, 'SPA asset not found.');
        }
        if ($resolvedRelative === '.' || !$this->isPublicAssetPath($resolvedRelative)) {
            return $this->errorResponse($request, 404, 'SPA asset not found.');
        }

        return $this->respond(
            $request,
            $file->asFile()->get_contents(),
            200,
            ['Content-Type' => MimeMap::fromExtension($file->getExtension())],
        );
    }

    /** Return a fixed diagnostic when the missing resource is not an HTML route. */
    private function fallbackRejection(ServerRequestInterface $request): ?string
    {
        // Browser senden für Skripte oft */*: Das Ladeziel muss dann entscheiden.
        if ($request->hasHeader('Sec-Fetch-Dest')) {
            $destination = trim($request->getHeaderLine('Sec-Fetch-Dest'), " \t");
            if ($destination === 'style') {
                return 'CSS requested.';
            }
            if (in_array($destination, [
                'script', 'worker', 'sharedworker', 'serviceworker',
                'audioworklet', 'paintworklet',
            ], true)) {
                return 'JavaScript requested.';
            }
            if (!in_array($destination, ['empty', 'document', 'frame', 'iframe'], true)) {
                return 'Asset requested.';
            }
        }

        // Ohne Accept gilt jede Medienart als akzeptiert; leer ist nicht fehlend.
        if (!$request->hasHeader('Accept')) {
            return null;
        }
        $ranges = $this->parseAccept($request->getHeaderLine('Accept'));
        if ($ranges === null) {
            return 'Invalid Accept header.';
        }

        $htmlQuality = 0;
        $htmlSpecificity = -1;
        $assetQuality = 0;
        $assetReason = null;
        foreach ($ranges as $range) {
            $type = $range['type'];
            $quality = $range['quality'];
            $resource = match ($type) {
                'text/css' => 'CSS requested.',
                'text/javascript', 'application/javascript',
                'text/ecmascript', 'application/ecmascript',
                'application/x-javascript', 'application/x-ecmascript',
                'text/x-javascript', 'text/x-ecmascript',
                'text/jscript', 'text/livescript' => 'JavaScript requested.',
                default => null,
            };
            if ($resource !== null && $quality > $assetQuality) {
                $assetQuality = $quality;
                $assetReason = $resource;
            }

            $specificity = match ($type) {
                '*/*' => 0,
                'text/*' => 2,
                'text/html' => 4,
                default => -1,
            };
            foreach ($range['parameters'] as $name => $value) {
                if ($name !== 'charset' || $value !== 'utf-8') {
                    $specificity = -1;
                    break;
                }
            }
            if ($specificity < 0) {
                continue;
            }
            $specificity += count($range['parameters']);
            if ($specificity > $htmlSpecificity) {
                $htmlSpecificity = $specificity;
                $htmlQuality = $quality;
            }
        }

        // Ein expliziter CSS-/JS-Wunsch geht einer schwächeren HTML-Wildcard vor.
        if ($assetReason !== null && (
            $assetQuality > $htmlQuality
            || ($assetQuality === $htmlQuality && $htmlSpecificity < 4)
        )) {
            return $assetReason;
        }
        return $htmlQuality > 0 ? null : 'HTML not accepted.';
    }

    /** @return list<array{type: string, parameters: array<string, string>, quality: int}>|null */
    private function parseAccept(string $header): ?array
    {
        if (strlen($header) > self::MAX_ACCEPT_BYTES || preg_match('/[\x00-\x08\x0a-\x1f\x7f-\xff]/', $header)) {
            return null;
        }
        $members = $this->splitHeaderValue($header, ',');
        if ($members === null || count($members) > self::MAX_ACCEPT_RANGES) {
            return null;
        }

        // Trennzeichen innerhalb zitierter Parameter sind keine Listengrenzen.
        $ranges = [];
        $seen = [];
        foreach ($members as $member) {
            if (trim($member) === '') {
                continue;
            }
            $parts = $this->splitHeaderValue($member, ';');
            if ($parts === null) {
                return null;
            }
            $type = strtolower(trim(array_shift($parts)));
            $media = explode('/', $type);
            if (count($media) !== 2 || !$this->isHttpToken($media[0]) || !$this->isHttpToken($media[1]) || ($media[0] === '*' && $media[1] !== '*')) {
                return null;
            }
            $quality = 1000;
            $parameters = [];
            $names = [];
            foreach ($parts as $part) {
                $parameter = explode('=', trim($part, " \t"), 2);
                $name = strtolower($parameter[0]);
                if (count($parameter) !== 2 || !$this->isHttpToken($name) || isset($names[$name])) {
                    return null;
                }
                $names[$name] = true;
                $rawValue = $parameter[1];
                if ($name === 'q') {
                    if (!preg_match('/^(?:0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/D', $rawValue)) {
                        return null;
                    }
                    $quality = (int) round((float) $rawValue * 1000);
                    continue;
                }
                $value = $this->decodeHeaderValue($rawValue);
                if ($value === null) {
                    return null;
                }
                $parameters[$name] = $name === 'charset' ? strtolower($value) : $value;
            }

            // Widersprüchliche Gewichte dürfen nicht von der Reihenfolge abhängen.
            ksort($parameters);
            $key = $type . ':' . json_encode($parameters);
            if (isset($seen[$key]) && $seen[$key] !== $quality) {
                return null;
            }
            $seen[$key] = $quality;
            $ranges[] = ['type' => $type, 'parameters' => $parameters, 'quality' => $quality];
        }
        return $ranges;
    }

    /** @return list<string>|null */
    private function splitHeaderValue(string $value, string $delimiter): ?array
    {
        // Zitierte Trennzeichen und escaped Anführungszeichen zusammenhalten.
        $parts = [];
        $start = 0;
        $quoted = false;
        $escaped = false;
        for ($index = 0, $length = strlen($value); $index < $length; $index++) {
            $character = $value[$index];
            if ($escaped) {
                $escaped = false;
            } elseif ($quoted && $character === '\\') {
                $escaped = true;
            } elseif ($character === '"') {
                $quoted = !$quoted;
            } elseif (!$quoted && $character === $delimiter) {
                $parts[] = substr($value, $start, $index - $start);
                $start = $index + 1;
            }
        }
        if ($quoted) {
            return null;
        }
        $parts[] = substr($value, $start);
        return $parts;
    }

    private function decodeHeaderValue(string $value): ?string
    {
        if (!str_starts_with($value, '"')) {
            return $this->isHttpToken($value) ? $value : null;
        }
        if (strlen($value) < 2 || !str_ends_with($value, '"')) {
            return null;
        }
        // Nur den Inhalt eines vollständig zitierten Parameterwerts dekodieren.
        $decoded = '';
        for ($index = 1, $end = strlen($value) - 1; $index < $end; $index++) {
            if ($value[$index] === '\\') {
                $index++;
                if ($index >= $end) {
                    return null;
                }
            } elseif ($value[$index] === '"') {
                return null;
            }
            $decoded .= $value[$index];
        }
        return $decoded;
    }

    private function isHttpToken(string $value): bool
    {
        return preg_match('/^[!#$%&\'*+.^_`|~0-9a-z-]+$/iD', $value) === 1;
    }

    private function missingAssetResponse(ServerRequestInterface $request, string $relativePath, ?string $reason = null): ResponseInterface
    {
        if ($reason === null) {
            $reason = match (strtolower(pathinfo($relativePath, PATHINFO_EXTENSION))) {
                'css' => 'CSS requested.',
                'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx' => 'JavaScript requested.',
                default => '',
            };
            if ($reason === '') {
                $hint = $this->fallbackRejection($request);
                if (in_array($hint, ['CSS requested.', 'JavaScript requested.'], true)) {
                    $reason = $hint;
                }
            }
        }
        $separator = strrpos($relativePath, '/');
        $directory = $separator === false ? '' : substr($relativePath, 0, $separator + 1);
        if ($relativePath === $this->configuredAssetDir) {
            $directory = $this->configuredAssetDir . '/';
        }
        $directory = rtrim($this->mount, '/') . '/' . $directory;
        // Ausschließlich das öffentliche URL-Verzeichnis zeigen, sicher kodiert.
        $directory = implode('/', array_map('rawurlencode', explode('/', $directory)));
        $message = 'File not Found (SPA-Serve Asset Path: ' . $directory . ').';
        if ($reason !== '') {
            $message .= ' ' . $reason;
        }
        return $this->errorResponse($request, 404, $message, ['Vary' => self::FALLBACK_VARY]);
    }

    private function decodeRequestPath(string $path): ?string
    {
        if (preg_match('/%(?![0-9a-f]{2})/i', $path)) {
            return null;
        }
        $path = rawurldecode($path === '' ? '/' : $path);

        // Genau einmal dekodieren; mehrdeutige Rest-Escapes nicht weiterreichen.
        if (
            !str_starts_with($path, '/')
            || strpbrk($path, '?#') !== false
            || preg_match('/[\x00-\x1f\x7f]|%[0-9a-f]{2}/i', $path)
            || preg_match('//u', $path) !== 1
        ) {
            return null;
        }

        // Unsaubere Request-Pfade ablehnen, nicht normalisieren.
        return phore_uri($path)->isClean() ? $path : null;
    }

    private function isPublicAssetPath(string $path): bool
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if (
                str_starts_with($segment, '.')
                || str_contains($segment, ':')
                || strlen($segment) > 255
                || str_ends_with($segment, '.')
                || str_ends_with($segment, ' ')
                || str_ends_with($segment, '~')
                || preg_match('/^(?:con(?:in\$|out\$)?|prn|aux|nul|com[1-9¹²³]|lpt[1-9¹²³])(?:[ .]|$)/iu', ltrim($segment, ' '))
                || in_array(strtolower($segment), [
                    'vendor', 'node_modules', 'composer.json', 'composer.lock',
                    'package.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock',
                ], true)
                || preg_match('/\.(?:php\d*|phtml|pht|phar|inc|env|ini|log|sql|sqlite3?|db|bak|backup|old|orig|save|sw[opn]|pem|key)(?:\.|$)/i', $segment)
            ) {
                return false;
            }
        }

        return true;
    }

    private function errorResponse(ServerRequestInterface $request, int $status, string $message, array $headers = []): ResponseInterface
    {
        return $this->respond($request, $message, $status, ['Content-Type' => 'text/plain; charset=utf-8', ...$headers]);
    }

    private function respond(ServerRequestInterface $request, string $body, int $status, array $headers): ResponseInterface
    {
        $headers['X-Content-Type-Options'] = 'nosniff';
        if ($status >= 400) {
            $headers['Cache-Control'] = 'no-store';
        }

        return $this->app->responseFactory->createResponseWithBody(
            $request->getMethod() === 'HEAD' ? '' : $body,
            $status,
            $headers,
        );
    }

    private function isMountedPath(string $path): bool
    {
        return $this->mount === '/' || $path === $this->mount || str_starts_with($path, $this->mount . '/');
    }

    private function relativePath(string $path): string
    {
        if ($path === $this->mount || $path === $this->mount . '/') {
            return '';
        }

        return phore_uri($path)
            ->rel($this->mount)
            ->formatUri(leadingSlash: false, trailingSlash: str_ends_with($path, '/'));
    }

    private function normalizeMount(string $mount): string
    {
        return phore_uri('/' . $mount)->formatUri(trailingSlash: false);
    }

    private function resolveExcludedPath(string $path): string
    {
        $path = $this->normalizeMount($path);
        if ($this->isMountedPath($path)) {
            return $path;
        }
        return phore_uri($this->mount . $path)->formatUri(trailingSlash: false);
    }
}
