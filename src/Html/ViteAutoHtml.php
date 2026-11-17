<?php

declare(strict_types=1);

namespace Brace\SpaServe\Html;

final class ViteAutoHtml extends HtmlGenerator
{
    /** @var array<string, scalar|null> */
    private array $configuredMeta;
    /** @var list<string> */
    private array $configuredCss;
    /** @var list<string> */
    private array $configuredJavascript;
    /** @var list<string> */
    private array $additionalCss;
    /** @var list<string> */
    private array $additionalJavascript;

    /**
     * Production uses $css/$javascript. In development those bundle assets are
     * replaced by the Vite client/entrypoint. Additional assets are always loaded.
     *
     * @param array<string, scalar|null> $meta
     * @param list<string> $css
     * @param list<string> $javascript
     * @param list<string> $additionalCss
     * @param list<string> $additionalJavascript
     * @param string|null $startHtml Trusted application HTML inserted at the start of <body>.
     */
    public function __construct(
        public bool $development,
        public string $appBasePath = '/',
        public string $apiBaseUrl = '',
        string $title = '',
        array $meta = [],
        array $css = [],
        array $javascript = [],
        array $additionalCss = [],
        array $additionalJavascript = [],
        ?string $startHtml = null,
        public string $devViteClient = '/@vite/client',
        public ?string $devEntrypoint = '/src/main.ts',
    ) {
        $this->configuredMeta = $meta;
        $this->configuredCss = $css;
        $this->configuredJavascript = $javascript;
        $this->additionalCss = $additionalCss;
        $this->additionalJavascript = $additionalJavascript;

        parent::__construct(title: $title, startHtml: $startHtml);
        $this->setApplicationUrls($appBasePath, $apiBaseUrl);
    }

    public function setApplicationUrls(string $appBasePath, string $apiBaseUrl = ''): void
    {
        $appBasePath = $this->normalizeBasePath($appBasePath);
        $this->appBasePath = $appBasePath;
        $this->apiBaseUrl = $apiBaseUrl !== '' ? rtrim($apiBaseUrl, '/') : rtrim($appBasePath, '/');

        $resolvedCss = $this->development ? $this->additionalCss : [...$this->configuredCss, ...$this->additionalCss];
        $resolvedJavascript = $this->development
            ? [
                $this->devViteClient,
                ...($this->devEntrypoint !== null ? [$this->devEntrypoint] : []),
                ...$this->additionalJavascript,
            ]
            : [...$this->configuredJavascript, ...$this->additionalJavascript];

        $this->meta = array_merge([
            'app-base-path' => $appBasePath,
            'api-base-url' => $this->apiBaseUrl,
        ], $this->configuredMeta);
        $this->css = array_map(fn (string $path) => $this->resolvePath($path, $appBasePath), $resolvedCss);
        $this->javascript = array_map(fn (string $path) => $this->resolvePath($path, $appBasePath), $resolvedJavascript);
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
