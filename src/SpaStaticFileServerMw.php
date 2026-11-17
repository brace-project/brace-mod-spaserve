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

    private ?PhoreDirectory $bundleDir = null;
    private ?string $bundleRoot = null;
    private bool $development = false;
    private PhoreDirectory|string $configuredBundleDir;
    private ?bool $configuredDevelopment;
    private ?string $configuredMount;
    public string $mount;

    /**
     * Configure SPA delivery after the application's API middleware.
     *
     * Development only serves the HTML shell; Vite serves source, assets and HMR.
     * Production reads files from an existing Vite output directory.
     *
     * @param PhoreDirectory|string $bundleDir Vite build output directory.
     * @param HtmlGenerator $html HTML shell for client side routes.
     * @param string $mount SPA URL prefix.
     * @param list<string> $excludePaths Backend paths that must reach the next handler.
     * @throws \RuntimeException If the production bundle directory is missing.
     * @see ViteAutoHtml
     * @example new SpaStaticFileServerMw(__DIR__ . '/dist', new ViteAutoHtml(development: true), excludePaths: ['/api']);
     */
    public function __construct(
        PhoreDirectory|string $bundleDir,
        public HtmlGenerator $html,
        ?string $mount = null,
        public array $excludePaths = ['/api'],
        public string $apiMountPath = '/api',
    ) {
        $this->configuredMount = $mount;
        $this->mount = $this->normalizeMount($mount ?? '/');
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
        // Die Root-Policy wird an alle späteren Dateioperationen vererbt.
        $this->bundleDir = null;
        $this->bundleRoot = null;
        if (!$this->development) {
            $directory = phore_dir($this->configuredBundleDir)->assertDirectory();
            $root = realpath((string) $directory);
            if ($root === false) {
                throw new FilesystemException("Cannot resolve SPA bundle directory '$directory'.");
            }
            $this->bundleRoot = $root;
            $this->bundleDir = phore_dir($directory, ['rootDir' => $root]);
        }
        if ($this->html instanceof ViteAutoHtml) {
            $this->html->development = $this->development;
            $this->html->setApplicationUrls(
                $this->mount,
                rtrim($this->mount, '/') . '/' . trim($this->apiMountPath, '/'),
            );
        }
        $this->html->meta['app-env'] = $app->environmentType->value;
    }

    /**
     * Serve a validated GET/HEAD asset or SPA route, or delegate an excluded API.
     *
     * URL decoding precedes every routing and filesystem decision. Invalid or
     * private paths return 404, unsupported methods 405 and operational errors
     * 500. Error bodies never include request input or filesystem diagnostics.
     * Excluded requests are passed to the handler unchanged.
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
        if (!$this->isMountedPath($path)) {
            return $this->errorResponse($request, 404, 'Requested path is outside the configured SPA URL prefix.');
        }
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $this->errorResponse($request, 405, 'SPA delivery only supports GET and HEAD.', ['Allow' => 'GET, HEAD']);
        }

        $relativePath = $this->relativePath($path);
        if (!$this->isPublicAssetPath($relativePath)) {
            return $this->errorResponse($request, 404, 'SPA asset not found.');
        }

        // Nur reguläre öffentliche Dateien aus dem gebundenen Bundle lesen.
        if (!$this->development) {
            try {
                $this->bundleDir->assertDirectory();
            } catch (FilesystemException) {
                return $this->errorResponse($request, 500, 'Unable to read the SPA bundle.');
            }

            try {
                if ($relativePath !== '') {
                    $assetResponse = $this->tryStaticFile($relativePath, $request);
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

        // A missing asset must never be returned as HTML: browsers reject it as JS/CSS.
        if ($relativePath === 'assets' || str_starts_with($relativePath, 'assets/') || pathinfo($relativePath, PATHINFO_EXTENSION) !== '') {
            return $this->errorResponse($request, 404, 'SPA asset not found.');
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
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    private function tryStaticFile(string $relativePath, ServerRequestInterface $request): ?ResponseInterface
    {
        $file = $this->bundleDir->withSubPath($relativePath);
        if (!$file->exists()) {
            return null;
        }
        if (!$file->isFile()) {
            return $this->errorResponse($request, 404, 'SPA asset not found.');
        }

        // Auch ein erlaubter interner Symlink darf keine private Datei freigeben.
        $resolved = realpath((string) $file);
        $rootPrefix = rtrim($this->bundleRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($resolved === false) {
            return $this->errorResponse($request, 500, 'Unable to read the SPA asset.');
        }
        if (!str_starts_with($resolved, $rootPrefix) || !$this->isPublicAssetPath(substr($resolved, strlen($rootPrefix)))) {
            return $this->errorResponse($request, 404, 'SPA asset not found.');
        }

        return $this->respond(
            $request,
            $file->asFile()->get_contents(),
            200,
            ['Content-Type' => MimeMap::fromExtension($file->getExtension())],
        );
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
            || str_contains($path, '//')
            || strpbrk($path, "\\?#") !== false
            || preg_match('/[\x00-\x1f\x7f]|%[0-9a-f]{2}/i', $path)
            || preg_match('//u', $path) !== 1
        ) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
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
        return ltrim($this->mount === '/' ? $path : substr($path, strlen($this->mount)), '/');
    }

    private function normalizeMount(string $mount): string
    {
        return '/' . trim($mount, '/');
    }

    private function resolveExcludedPath(string $path): string
    {
        $path = $this->normalizeMount($path);
        if ($this->mount === '/' || $path === $this->mount || str_starts_with($path, $this->mount . '/')) {
            return $path;
        }
        return $this->mount . $path;
    }
}
