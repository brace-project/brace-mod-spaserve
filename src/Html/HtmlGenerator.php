<?php

declare(strict_types=1);

namespace Brace\SpaServe\Html;

class HtmlGenerator
{
    /**
     * @param array<string, scalar|null> $meta
     * @param list<string> $css
     * @param list<string> $javascript
     * @param string|null $startHtml Trusted application HTML inserted at the start of <body>.
     */
    public function __construct(
        public string $title = '',
        public array $meta = [],
        public array $css = [],
        public array $javascript = [],
        public ?string $startHtml = null,
    ) {
    }

    public function render(): string
    {
        $head = [
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
        ];

        foreach ($this->meta as $name => $value) {
            if ($value === null) {
                continue;
            }
            $head[] = sprintf('<meta name="%s" content="%s">', $this->escape((string) $name), $this->escape((string) $value));
        }

        if ($this->title !== '') {
            $head[] = '<title>' . $this->escape($this->title) . '</title>';
        }

        foreach ($this->css as $href) {
            $head[] = sprintf('<link rel="stylesheet" href="%s">', $this->escape($href));
        }

        $body = [];
        if ($this->startHtml !== null) {
            $body[] = $this->startHtml;
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

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
