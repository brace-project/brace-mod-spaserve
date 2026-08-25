<?php

declare(strict_types=1);

namespace Brace\SpaServe\Html;

final class ViteAutoHtml extends HtmlGenerator
{
    private function __construct(
        public bool $development,
        public string $basePath = '/',
        public ?string $entrypoint = null,
        public string $viteClient = '/@vite/client',
        string $title = '',
        array $meta = [],
        array $css = [],
        array $javascript = [],
        string|array|null $startElement = null,
    ) {
        $basePath = $this->normalizeBasePath($basePath);
        $this->basePath = $basePath;

        $scripts = [];
        if ($development) {
            $scripts[] = $this->resolvePath($viteClient, $basePath);
            if ($entrypoint !== null) {
                $scripts[] = $this->resolvePath($entrypoint, $basePath);
            }
        }

        foreach ($javascript as $path) {
            $scripts[] = $this->resolvePath($path, $basePath);
        }

        parent::__construct(
            title: $title,
            meta: array_merge([
                'spa-base-path' => $basePath,
                'spa-api-base-url' => $basePath === '/' ? '' : rtrim($basePath, '/'),
            ], $meta),
            css: array_map(fn (string $path) => $this->resolvePath($path, $basePath), $css),
            javascript: $scripts,
            startElement: $startElement,
        );
    }

    /**
     * @param array<string, scalar|null> $meta
     * @param list<string> $css
     * @param list<string> $javascript
     * @param string|array{tag:string, attributes?:array<string, scalar|null>}|null $startElement
     */
    public static function development(
        string $entrypoint,
        string $basePath = '/',
        string $viteClient = '/@vite/client',
        string $title = '',
        array $meta = [],
        array $css = [],
        array $javascript = [],
        string|array|null $startElement = null,
    ): self {
        return new self(
            development: true,
            basePath: $basePath,
            entrypoint: $entrypoint,
            viteClient: $viteClient,
            title: $title,
            meta: $meta,
            css: $css,
            javascript: $javascript,
            startElement: $startElement,
        );
    }

    /**
     * Production does not inspect a Vite manifest. Pass the built CSS/JS files explicitly.
     *
     * @param array<string, scalar|null> $meta
     * @param list<string> $css
     * @param list<string> $javascript
     * @param string|array{tag:string, attributes?:array<string, scalar|null>}|null $startElement
     */
    public static function production(
        string $basePath = '/',
        string $title = '',
        array $meta = [],
        array $css = [],
        array $javascript = [],
        string|array|null $startElement = null,
    ): self {
        return new self(
            development: false,
            basePath: $basePath,
            title: $title,
            meta: $meta,
            css: $css,
            javascript: $javascript,
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
