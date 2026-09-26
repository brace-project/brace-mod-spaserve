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

/** Serves the SPA shell and, in production, the files emitted by Vite. */
final class SpaStaticFileServerMw extends BraceAbstractMiddleware
{
    private ?PhoreDirectory $bundleDir;
    private bool $development;

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
        public string $mount = '/',
        public array $excludePaths = ['/api'],
    ) {
        $this->mount = $this->normalizeMount($mount);
        $this->development = $html instanceof ViteAutoHtml && $html->development;
        $this->bundleDir = $this->development ? null : phore_dir($bundleDir)->assertDirectory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        foreach ($this->excludePaths as $excludePath) {
            $excludePath = $this->normalizeMount($excludePath);
            if ($path === $excludePath || str_starts_with($path, $excludePath . '/')) {
                return $handler->handle($request);
            }
        }
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

        // A missing asset must never be returned as HTML: browsers reject it as JS/CSS.
        if (str_starts_with($relativePath, 'assets/') || pathinfo($relativePath, PATHINFO_EXTENSION) !== '') {
            return $this->app->responseFactory->createResponse(404);
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
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            return null;
        }
        $segments = explode('/', str_replace('\\', '/', $relativePath));
        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return null;
        }

        $file = $this->bundleDir->withRelativePath($relativePath);
        if (!$file->exists() || !$file->isFile()) {
            return null;
        }
        $root = realpath((string) $this->bundleDir);
        $resolved = realpath((string) $file);
        if ($root === false || $resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $this->app->responseFactory->createResponseWithBody(
            $file->get_contents(),
            200,
            ['Content-Type' => MimeMap::fromExtension($file->getExtension())],
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
}
