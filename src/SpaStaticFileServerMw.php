<?php

declare(strict_types=1);

namespace Brace\SpaServe;

use Brace\Core\Base\BraceAbstractMiddleware;
use Phore\FileSystem\PhoreDirectory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves one SPA index for every route below $mount.
 *
 * In development the index receives Vite module includes. Vite itself is
 * expected to run in front of the PHP application and handle those requests.
 * In production static files are read from $bundleDir and every other request
 * falls back to index.html.
 */
final class SpaStaticFileServerMw extends BraceAbstractMiddleware
{
    private const MIME_MAP = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'pdf' => 'application/pdf',
    ];

    private PhoreDirectory $bundleDir;

    /**
     * @param PhoreDirectory|string $bundleDir Directory containing production bundles and index.html.
     * @param string $mount URL prefix of the SPA, e.g. "/" or "/admin".
     * @param bool $development Add Vite includes instead of relying on built entry assets.
     * @param string $indexFile File in $bundleDir used as SPA shell.
     * @param string|null $developmentIndexFile Optional source index used only in development.
     * @param string|null $viteEntry Vite application entry, e.g. "/src/main.ts". Null disables it.
     * @param string $viteClient Vite HMR client path.
     * @param array<string, scalar|null> $meta Runtime values injected as <meta name="spa-*">.
     */
    public function __construct(
        PhoreDirectory|string $bundleDir,
        public string $mount = '/',
        public bool $development = false,
        public string $indexFile = 'index.html',
        public ?string $developmentIndexFile = null,
        public ?string $viteEntry = '/src/main.ts',
        public string $viteClient = '/@vite/client',
        public array $meta = [],
    ) {
        $this->bundleDir = phore_dir($bundleDir)->assertDirectory();
        $this->mount = $this->normalizeMount($this->mount);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!$this->isMountedPath($path)) {
            return $handler->handle($request);
        }

        $relativePath = $this->relativePath($path);

        // Vite is expected to be in front of the application in development.
        // SPA-Surf therefore only serves the shell and never implements a build/proxy pipeline itself.
        if (!$this->development && $relativePath !== '') {
            $assetResponse = $this->tryStaticFile($relativePath);
            if ($assetResponse !== null) {
                return $assetResponse;
            }
        }

        return $this->htmlResponse($request);
    }

    private function htmlResponse(ServerRequestInterface $request): ResponseInterface
    {
        $indexFile = $this->development && $this->developmentIndexFile !== null
            ? phore_file($this->developmentIndexFile)->assertFile()
            : $this->bundleDir->withRelativePath($this->indexFile)->assertFile();

        $html = $indexFile->get_contents();
        $html = $this->injectHead($html, $this->renderRuntimeHead($request));

        return $this->app->responseFactory->createResponseWithBody(
            $html,
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    private function renderRuntimeHead(ServerRequestInterface $request): string
    {
        $meta = array_merge([
            'base-path' => $this->mount,
            // Same-origin by default. Consumers may use this directly as api-stub baseUrl.
            'api-base-url' => $this->mount === '/' ? '' : $this->mount,
            'request-origin' => $this->requestOrigin($request),
        ], $this->meta);

        $head = [];
        foreach ($meta as $name => $value) {
            if ($value === null) {
                continue;
            }
            $head[] = sprintf(
                '<meta name="spa-%s" content="%s">',
                $this->escape((string)$name),
                $this->escape((string)$value),
            );
        }

        if ($this->development) {
            $head[] = sprintf('<script type="module" src="%s"></script>', $this->escape($this->viteClient));
            if ($this->viteEntry !== null) {
                $head[] = sprintf('<script type="module" src="%s"></script>', $this->escape($this->viteEntry));
            }
        }

        return implode("\n", $head);
    }

    private function tryStaticFile(string $relativePath): ?ResponseInterface
    {
        $relativePath = rawurldecode($relativePath);
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }

        $segments = explode('/', str_replace('\\', '/', $relativePath));
        if (in_array('..', $segments, true)) {
            return null;
        }

        $file = $this->bundleDir->withRelativePath($relativePath);
        if (!$file->exists() || !$file->isFile()) {
            return null;
        }

        $extension = strtolower($file->getExtension());
        $contentType = self::MIME_MAP[$extension] ?? 'application/octet-stream';

        return $this->app->responseFactory->createResponseWithBody(
            $file->get_contents(),
            200,
            ['Content-Type' => $contentType],
        );
    }

    private function injectHead(string $html, string $head): string
    {
        if ($head === '') {
            return $html;
        }

        if (preg_match('/<\/head\s*>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $match[0][1];
            return substr($html, 0, $offset) . $head . "\n" . substr($html, $offset);
        }

        return $head . "\n" . $html;
    }

    private function isMountedPath(string $path): bool
    {
        if ($this->mount === '/') {
            return true;
        }

        return $path === $this->mount || str_starts_with($path, $this->mount . '/');
    }

    private function relativePath(string $path): string
    {
        if ($this->mount === '/') {
            return ltrim($path, '/');
        }

        return ltrim(substr($path, strlen($this->mount)), '/');
    }

    private function normalizeMount(string $mount): string
    {
        $mount = '/' . trim($mount, '/');
        return $mount === '/' ? '/' : rtrim($mount, '/');
    }

    private function requestOrigin(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        if ($uri->getHost() === '') {
            return '';
        }

        $origin = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null) {
            $origin .= ':' . $uri->getPort();
        }
        return $origin;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
