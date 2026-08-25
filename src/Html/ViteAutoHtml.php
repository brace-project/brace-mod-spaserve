<?php

declare(strict_types=1);

namespace Brace\SpaServe\Html;

final class ViteAutoHtml extends HtmlGenerator
{
    /**
     * Production uses $css/$javascript. In development those bundle assets are
     * replaced by the Vite client/entrypoint. Additional assets are always loaded.
     *
     * @param array<string, scalar|null> $meta
     * @param list<string> $css
     * @param list<string> $javascript
     * @param list<string> $additionalCss
     * @param list<string> $additionalJavascript
     * @param string|array{tag:string, attributes?:array<string, scalar|null>}|null $startElement
     */
    public function __construct(
        public bool $development,
        public string $basePath = '/',
        string $title = '',
        array $meta = [],
        array $css = [],
        array $javascript = [],
        array $additionalCss = [],
        array $additionalJavascript = [],
        string|array|null $startElement = null,
        public string $devViteClient = '/@vite/client',
        public ?string $devEntrypoint = '/src/main.ts',
    ) {
        $basePath = $this->normalizeBasePath($basePath);
        $this->basePath = $basePath;

        $resolvedCss = $development ? $additionalCss : [...$css, ...$additionalCss];
        $resolvedJavascript = $development
            ? [
                $devViteClient,
                ...($devEntrypoint !== null ? [$devEntrypoint] : []),
                ...$additionalJavascript,
            ]
            : [...$javascript, ...$additionalJavascript];

        parent::__construct(
            title: $title,
            meta: array_merge([
                'spa-base-path' => $basePath,
                'spa-api-base-url' => $basePath === '/' ? '' : rtrim($basePath, '/'),
            ], $meta),
            css: array_map(fn (string $path) => $this->resolvePath($path, $basePath), $resolvedCss),
            javascript: array_map(fn (string $path) => $this->resolvePath($path, $basePath), $resolvedJavascript),
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
