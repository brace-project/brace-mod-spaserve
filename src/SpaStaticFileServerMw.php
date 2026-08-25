<?php

declare(strict_types=1);

namespace Brace\SpaServe;

use Brace\Core\Base\BraceAbstractMiddleware;
use Brace\SpaServe\Html\HtmlGenerator;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\Tools\MimeMap;
use Phore\FileSystem\PhoreDirectory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves a generated SPA shell for every non-asset route below $mount.
 *
 * Development mode is derived from ViteAutoHtml::development(). In that mode
 * Vite is expected to run in front of PHP and SPA-Surf only returns the shell.
 * Production serves existing files from $bundleDir and falls back to the shell.
 */
final class SpaStaticFileServerMw extends BraceAbstractMiddleware
{
    private PhoreDirectory $bundleDir;
    private bool $development;

    public function __construct(
        PhoreDirectory|string $bundleDir,
        public HtmlGenerator $html,
        public string $mount = '/',
    ) {
        $this->bundleDir = phore_dir($bundleDir)->assertDirectory();
        $this->mount = $this->normalizeMount($this->mount);
        $this->development = $html instanceof ViteAutoHtml && $html->development;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (!$this->isMountedPath($path)) {
            return $handler->handle($request);
        }

        $relativePath = $this->relativePath($path);

        if (!$this->development && $relativePath !== '') {
            $assetResponse = $this->tryStaticFile($relativePath);
            if ($assetResponse !== null) {
                return $assetResponse;
            }
        }

        return $this->app->responseFactory->createResponseWithBody(
            $this->html->render(),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }

    private function tryStaticFile(string $relativePath): ?ResponseInterface
    {
        $relativePath = rawurldecode($relativePath);
        if ($relativePath === '' || str_contains($relativePath, "\0")) return null;

        $segments = explode('/', str_replace('\\', '/', $relativePath));
        if (in_array('..', $segments, true)) return null;

        $file = $this->bundleDir->withRelativePath($relativePath);
        if (!$file->exists() || !$file->isFile()) return null;

        return $this->app->responseFactory->createResponseWithBody(
            $file->get_contents(),
            200,
            ['Content-Type' => MimeMap::fromExtension($file->getExtension())],
        );
    }

    private function isMountedPath(string $path): bool
    {
        if ($this->mount === '/') return true;
        return $path === $this->mount || str_starts_with($path, $this->mount . '/');
    }

    private function relativePath(string $path): string
    {
        if ($this->mount === '/') return ltrim($path, '/');
        return ltrim(substr($path, strlen($this->mount)), '/');
    }

    private function normalizeMount(string $mount): string
    {
        $mount = '/' . trim($mount, '/');
        return $mount === '/' ? '/' : rtrim($mount, '/');
    }
}
