<?php

declare(strict_types=1);

namespace Brace\SpaServe\Html;

class HtmlGenerator
{
    /**
     * @param array<string, scalar|null> $meta
     * @param list<string> $css
     * @param list<string> $javascript
     * @param string|array{tag:string, attributes?:array<string, scalar|null>}|null $startElement
     */
    public function __construct(
        public string $title = '',
        public array $meta = [],
        public array $css = [],
        public array $javascript = [],
        public string|array|null $startElement = null,
    ) {
    }

    public function render(): string
    {
        $head = [
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
        ];

        foreach ($this->meta as $name => $value) {
            if ($value === null) continue;
            $head[] = sprintf('<meta name="%s" content="%s">', $this->escape((string)$name), $this->escape((string)$value));
        }

        if ($this->title !== '') {
            $head[] = '<title>' . $this->escape($this->title) . '</title>';
        }

        foreach ($this->css as $href) {
            $head[] = sprintf('<link rel="stylesheet" href="%s">', $this->escape($href));
        }

        $body = [];
        if ($this->startElement !== null) {
            $body[] = $this->renderStartElement($this->startElement);
        }

        foreach ($this->javascript as $src) {
            $body[] = sprintf('<script type="module" src="%s"></script>', $this->escape($src));
        }

        return "<!doctype html>\n<html>\n<head>\n    "
            . implode("\n    ", $head)
            . "\n</head>\n<body>\n    "
            . implode("\n    ", $body)
            . "\n</body>\n</html>\n";
    }

    /** @param string|array{tag:string, attributes?:array<string, scalar|null>} $element */
    private function renderStartElement(string|array $element): string
    {
        if (is_string($element)) {
            return sprintf('<%1$s></%1$s>', $this->escapeTag($element));
        }

        $tag = $this->escapeTag($element['tag']);
        $attributes = [];
        foreach ($element['attributes'] ?? [] as $name => $value) {
            if ($value === null) continue;
            $attributes[] = sprintf('%s="%s"', $this->escape((string)$name), $this->escape((string)$value));
        }

        return sprintf('<%1$s%2$s></%1$s>', $tag, $attributes === [] ? '' : ' ' . implode(' ', $attributes));
    }

    private function escapeTag(string $tag): string
    {
        if (!preg_match('/^[a-z][a-z0-9._-]*$/i', $tag)) {
            throw new \InvalidArgumentException("Invalid start element tag: {$tag}");
        }
        return $tag;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
