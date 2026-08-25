<?php

declare(strict_types=1);

namespace Brace\SpaServe\Html;

final class ViteAutoHtml extends HtmlGenerator
{
    /**
     * @param array<string, scalar|null> $meta
     * @param list<string> $css
     * @param list<string> $javascript Additional module scripts loaded after the Vite entry.
     * @param string|array{tag:string, attributes?:array<string, scalar|null>}|null $startElement
     */
    public function __construct(
        public string $basePath = '/',
        string $entrypoint = '/src/main.ts',
        string $viteClient = '/@vite/client',
        string $title = '',
        array $meta = [],
        array $css = [],
        array $javascript = [],
        string|array|null $startElement = null,
    ) {
        $basePath = $this->normalizeBasePath($basePath);
        $this->basePath = $basePath;

        parent::__construct(
            title: $title,
            meta: array_merge([
                'spa-base-path' => $basePath,
                'spa-api-base-url' => $basePath === '/' ? '' : $basePath,
            ], $meta),
            css: array_map(fn (string $path) => $this->resolvePath($path, $basePath), $css),
            javascript: array_merge(
                [$this->resolvePath($viteClient, $basePath), $this->resolvePath($entrypoint, $basePath)],
                array_map(fn (string $path) => $this->resolvePath($path, $basePath), $javascript),
            ),
            startElement: $startElement,
        );
    }

    private function normalizeBasePath(string $basePath): string
    {
        $basePath = '/' . trim($basePath, '/');
        return $basePath === '/' ? '/' : $basePath . '/';
    }

    private function resolvePath(string $path, string $basePath): string
    {
        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
            return $path;
        }

        return $basePath . ltrim($path, '/');
    }
}
