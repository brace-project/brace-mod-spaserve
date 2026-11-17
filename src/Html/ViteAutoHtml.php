<?php

declare(strict_types=1);

namespace Brace\SpaServe\Html;

final class ViteAutoHtml extends HtmlGenerator
{
    /** @var array<string, scalar|null> */
    private array $configuredMeta;
    private string $configuredApiBaseUrl;
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
        public ?bool $development = null,
        public string $url_prefix = '/',
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
        $this->configuredApiBaseUrl = $apiBaseUrl;
        $this->configuredCss = $css;
        $this->configuredJavascript = $javascript;
        $this->additionalCss = $additionalCss;
        $this->additionalJavascript = $additionalJavascript;

        parent::__construct(title: $title, startHtml: $startHtml);
        $this->setApplicationUrls($url_prefix);
    }

    public function setApplicationUrls(string $url_prefix, string $defaultApiBaseUrl = ''): void
    {
        $url_prefix = $this->normalizeBasePath($url_prefix);
        $this->url_prefix = $url_prefix;
        $this->apiBaseUrl = rtrim(
            $this->configuredApiBaseUrl !== ''
                ? $this->configuredApiBaseUrl
                : ($defaultApiBaseUrl !== '' ? $defaultApiBaseUrl : rtrim($url_prefix, '/') . '/api'),
            '/',
        );

        $resolvedCss = $this->development ? $this->additionalCss : [...$this->configuredCss, ...$this->additionalCss];
        $resolvedJavascript = $this->development
            ? [
                $this->devViteClient,
                ...($this->devEntrypoint !== null ? [$this->devEntrypoint] : []),
                ...$this->additionalJavascript,
            ]
            : [...$this->configuredJavascript, ...$this->additionalJavascript];

        $this->meta = array_merge([
            'app-base-path' => $url_prefix,
            'api-base-url' => $this->apiBaseUrl,
        ], $this->configuredMeta);
        $this->css = array_map(fn (string $path) => $this->resolvePath($path, $url_prefix), $resolvedCss);
        $this->javascript = array_map(fn (string $path) => $this->resolvePath($path, $url_prefix), $resolvedJavascript);
    }

    private function normalizeBasePath(string $basePath): string
    {
        $basePath = phore_uri('/' . $basePath)->clean()->getUri();
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
