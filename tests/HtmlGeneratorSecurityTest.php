<?php

declare(strict_types=1);

namespace Brace\SpaServe\Tests;

use Brace\SpaServe\Html\HtmlGenerator;
use Brace\SpaServe\Html\ViteAutoHtml;
use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Security regression tests: changes require the approval described in AGENTS.md.
 * Asset URLs and startHtml remain trusted application configuration.
 */
final class HtmlGeneratorSecurityTest extends TestCase
{
    public static function renderingModes(): iterable
    {
        yield 'plain HTML' => ['html'];
        yield 'Vite production' => ['vite-production'];
        yield 'Vite development' => ['vite-development'];
    }

    public static function escapedTextCases(): iterable
    {
        $payloads = [
            'closing tags and quotes' => [
                "\"'> & </title><script id=\"injected\">alert(1)</script>",
                '&quot;&#039;&gt; &amp; &lt;/title&gt;&lt;script id=&quot;injected&quot;&gt;alert(1)&lt;/script&gt;',
            ],
            'event attributes' => [
                '" autofocus onfocus="alert(1)" data-x="',
                '&quot; autofocus onfocus=&quot;alert(1)&quot; data-x=&quot;',
            ],
            'pre-encoded markup' => [
                '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;&#x27;',
                '&amp;quot;&amp;gt;&amp;lt;img src=x onerror=alert(1)&amp;gt;&amp;#x27;',
            ],
        ];

        foreach (self::renderingModes() as $modeName => [$mode]) {
            foreach ($payloads as $payloadName => [$payload, $encoded]) {
                yield $modeName . ': ' . $payloadName => [$mode, $payload, $encoded];
            }
        }
    }

    #[DataProvider('escapedTextCases')]
    public function test_title_and_metadata_remain_text(string $mode, string $payload, string $encoded): void
    {
        $output = $this->generator($mode, [
            'title' => $payload,
            'meta' => [$payload => $payload, 'zero' => 0, 'false' => false, 'omitted' => null],
        ])->render();

        // Die erwartete Kodierung ist fest vorgegeben, nicht aus dem Produktcode berechnet.
        self::assertStringContainsString('<title>' . $encoded . '</title>', $output);
        self::assertStringContainsString('<meta name="' . $encoded . '" content="' . $encoded . '">', $output);
        self::assertStringNotContainsString('name="omitted"', $output);

        // Auch nach HTML-Parsing dürfen weder neue Elemente noch Attribute entstehen.
        $document = $this->parse($output);
        $this->assertSafeStructure($document, $this->defaultScriptCount($mode), 0, $this->defaultMetaCount($mode) + 3);
        self::assertSame(1, $document->getElementsByTagName('title')->length);
        self::assertSame($payload, $document->getElementsByTagName('title')->item(0)->textContent);
        self::assertSame($payload, $this->meta($document, $payload)->getAttribute('content'));
        self::assertSame('0', $this->meta($document, 'zero')->getAttribute('content'));
        self::assertSame('', $this->meta($document, 'false')->getAttribute('content'));
    }

    public static function assetUrlCases(): iterable
    {
        $contexts = [
            ['html', 'css'],
            ['html', 'javascript'],
            ['vite-production', 'css'],
            ['vite-production', 'javascript'],
            ['vite-production', 'additionalCss'],
            ['vite-production', 'additionalJavascript'],
            ['vite-development', 'devViteClient'],
            ['vite-development', 'devEntrypoint'],
            ['vite-development', 'additionalCss'],
            ['vite-development', 'additionalJavascript'],
        ];
        $payloads = [
            'attribute breakout' => [
                '" onload="alert(1)" data-x="',
                '&quot; onload=&quot;alert(1)&quot; data-x=&quot;',
            ],
            'tag and entity breakout' => [
                '"><svg onload=alert(1)>&#34;',
                '&quot;&gt;&lt;svg onload=alert(1)&gt;&amp;#34;',
            ],
        ];

        foreach ($contexts as [$mode, $field]) {
            foreach ($payloads as $name => [$payload, $encoded]) {
                yield $mode . ' ' . $field . ': ' . $name => [$mode, $field, $payload, $encoded];
            }
        }
    }

    #[DataProvider('assetUrlCases')]
    public function test_asset_urls_cannot_break_out_of_quoted_attributes(
        string $mode,
        string $field,
        string $payload,
        string $encoded,
    ): void {
        $isCss = in_array($field, ['css', 'additionalCss'], true);
        $isList = in_array($field, ['css', 'javascript', 'additionalCss', 'additionalJavascript'], true);
        $path = 'assets/security.' . ($isCss ? 'css' : 'js') . '?label=';
        $output = $this->generator($mode, [$field => $isList ? [$path . $payload] : $path . $payload])->render();

        // Relative Vite-URLs müssen trotz der Payload unter dem konfigurierten Mount bleiben.
        $prefix = $mode === 'html' ? '' : '/demo/';
        $attribute = $isCss ? 'href' : 'src';
        self::assertStringContainsString($attribute . '="' . $prefix . $path . $encoded . '"', $output);

        $document = $this->parse($output);
        $extraScript = $isCss || str_starts_with($field, 'dev') ? 0 : 1;
        $this->assertSafeStructure(
            $document,
            $this->defaultScriptCount($mode) + $extraScript,
            $isCss ? 1 : 0,
            $this->defaultMetaCount($mode),
        );

        // Der Browser muss die ganze Payload als einen einzigen Attributwert lesen.
        $values = [];
        foreach ($document->getElementsByTagName($isCss ? 'link' : 'script') as $element) {
            $values[] = $element->getAttribute($attribute);
        }
        self::assertContains($prefix . $path . $payload, $values);
    }

    public static function applicationUrlCases(): iterable
    {
        foreach (['vite-production', 'vite-development'] as $mode) {
            yield $mode . ' application mount' => [
                $mode,
                'url_prefix',
                '/demo" data-x="<svg onload=alert(1)>',
                '/demo&quot; data-x=&quot;&lt;svg onload=alert(1)&gt;/',
            ];
        }
    }

    #[DataProvider('applicationUrlCases')]
    public function test_application_urls_cannot_inject_metadata_or_asset_attributes(
        string $mode,
        string $field,
        string $payload,
        string $encoded,
    ): void {
        $output = $this->generator($mode, [
            $field => $payload,
            'css' => ['assets/app.css'],
            'javascript' => ['assets/app.js'],
            'additionalCss' => ['assets/extra.css'],
            'additionalJavascript' => ['assets/extra.js'],
        ])->render();
        $name = 'app-url-prefix';
        self::assertStringContainsString('<meta name="' . $name . '" content="' . $encoded . '">', $output);

        // Mount-Werte fließen zusätzlich in relative Asset-URLs ein.
        if ($field === 'url_prefix') {
            self::assertStringContainsString('href="' . $encoded . 'assets/extra.css"', $output);
            self::assertStringContainsString('src="' . $encoded . 'assets/extra.js"', $output);
        }

        $document = $this->parse($output);
        $development = $mode === 'vite-development';
        $this->assertSafeStructure($document, $development ? 3 : 2, $development ? 1 : 2, 3);
        self::assertSame($payload . ($field === 'url_prefix' ? '/' : ''), $this->meta($document, $name)->getAttribute('content'));
    }

    #[DataProvider('renderingModes')]
    public function test_invalid_utf8_is_replaced_without_exposing_markup(string $mode): void
    {
        $payload = "broken\xFF\"><img src=x onerror=alert(1)>";
        $decoded = "broken\u{FFFD}\"><img src=x onerror=alert(1)>";
        $encoded = "broken\u{FFFD}" . '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;';
        $cssField = $mode === 'vite-development' ? 'additionalCss' : 'css';
        $javascriptField = $mode === 'vite-development' ? 'additionalJavascript' : 'javascript';
        $output = $this->generator($mode, [
            'title' => $payload,
            'meta' => ['invalid' => $payload],
            $cssField => ['/assets/' . $payload . '.css'],
            $javascriptField => ['/assets/' . $payload . '.js'],
        ])->render();

        // Ungültige Bytes dürfen weder den Wert löschen noch die HTML-Grenzen öffnen.
        self::assertSame(1, preg_match('//u', $output));
        self::assertStringContainsString('<title>' . $encoded . '</title>', $output);
        self::assertStringContainsString('href="/assets/' . $encoded . '.css"', $output);
        self::assertStringContainsString('src="/assets/' . $encoded . '.js"', $output);

        $document = $this->parse($output);
        $this->assertSafeStructure($document, $this->defaultScriptCount($mode) + 1, 1, $this->defaultMetaCount($mode) + 1);
        self::assertSame($decoded, $document->getElementsByTagName('title')->item(0)->textContent);
        self::assertSame($decoded, $this->meta($document, 'invalid')->getAttribute('content'));
    }

    #[DataProvider('renderingModes')]
    public function test_unicode_text_is_preserved_while_ampersands_are_encoded(string $mode): void
    {
        $payload = 'Grüße — 東京 👩🏽‍💻 & Café';
        $output = $this->generator($mode, ['title' => $payload, 'meta' => ['Beschreibung-東京' => $payload]])->render();
        self::assertStringContainsString('<title>Grüße — 東京 👩🏽‍💻 &amp; Café</title>', $output);

        $document = $this->parse($output);
        $this->assertSafeStructure($document, $this->defaultScriptCount($mode), 0, $this->defaultMetaCount($mode) + 1);
        self::assertSame($payload, $document->getElementsByTagName('title')->item(0)->textContent);
        self::assertSame($payload, $this->meta($document, 'Beschreibung-東京')->getAttribute('content'));
    }

    #[DataProvider('renderingModes')]
    public function test_trusted_start_html_is_preserved_once_before_module_scripts(string $mode): void
    {
        $trusted = '<div id="startup"><span data-label="A &amp; B">Trusted shell</span></div>';
        $output = $this->generator($mode, ['startHtml' => $trusted, 'javascript' => ['assets/app.js']])->render();
        self::assertSame(1, substr_count($output, $trusted));
        $markupPosition = strpos($output, $trusted);
        $scriptPosition = strpos($output, '<script type="module"');
        self::assertIsInt($markupPosition);
        self::assertIsInt($scriptPosition);
        self::assertLessThan($scriptPosition, $markupPosition);

        // Vertrauenswürdiges Start-Markup ist kein Textfeld und wird nicht umgeschrieben.
        $document = $this->parse($output);
        $this->assertSafeStructure($document, $mode === 'vite-development' ? 2 : 1, 0, $this->defaultMetaCount($mode));
        $startup = $document->getElementById('startup');
        self::assertInstanceOf(DOMElement::class, $startup);
        self::assertSame('div', $startup->tagName);
        self::assertSame('Trusted shell', $startup->textContent);
        self::assertSame('A & B', $startup->getElementsByTagName('span')->item(0)->getAttribute('data-label'));
    }

    private function generator(string $mode, array $configuration = []): HtmlGenerator
    {
        if ($mode === 'html') {
            return new HtmlGenerator(...$configuration);
        }

        return new ViteAutoHtml(...array_replace([
            'development' => $mode === 'vite-development',
            'url_prefix' => '/demo/',
        ], $configuration));
    }

    private function defaultScriptCount(string $mode): int
    {
        return $mode === 'vite-development' ? 2 : 0;
    }

    private function defaultMetaCount(string $mode): int
    {
        return $mode === 'html' ? 2 : 3;
    }

    private function parse(string $html): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            // HTML4-Strukturprüfung für PHP 8.3; die Tests prüfen zusätzlich den Originaltext.
            libxml_clear_errors();
            $document = new DOMDocument('1.0', 'UTF-8');
            self::assertTrue($document->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_NONET));
            $errors = array_map(static fn ($error): string => trim($error->message), libxml_get_errors());
            self::assertSame([], $errors, 'Generated HTML must parse without libxml errors.');
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function meta(DOMDocument $document, string $name): DOMElement
    {
        foreach ($document->getElementsByTagName('meta') as $element) {
            if ($element->getAttribute('name') === $name) {
                return $element;
            }
        }
        self::fail('Expected metadata is missing: ' . $name);
    }

    private function assertSafeStructure(DOMDocument $document, int $scripts, int $links, int $metas): void
    {
        self::assertSame($scripts, $document->getElementsByTagName('script')->length);
        self::assertSame($links, $document->getElementsByTagName('link')->length);
        self::assertSame($metas, $document->getElementsByTagName('meta')->length);

        // Genau eine vertrauenswürdige Base ist zulässig; Payloads dürfen weder
        // weitere Base-Elemente noch Attribute einschleusen.
        $bases = $document->getElementsByTagName('base');
        self::assertSame(1, $bases->length);
        $base = $bases->item(0);
        self::assertInstanceOf(DOMElement::class, $base);
        self::assertSame('/', $base->getAttribute('href'));
        self::assertSame(1, $base->attributes->length);

        // Die Test-Payload darf keine ausführbaren Zusatz-Elemente erzeugen.
        foreach (['img', 'svg', 'iframe', 'object', 'embed', 'style'] as $tag) {
            self::assertSame(0, $document->getElementsByTagName($tag)->length, 'Unexpected element: ' . $tag);
        }
        foreach ($document->getElementsByTagName('script') as $script) {
            self::assertSame('module', $script->getAttribute('type'));
            self::assertTrue($script->hasAttribute('src'));
            self::assertSame('', $script->textContent);
        }

        // Attribute werden am geparsten DOM geprüft, nicht nur per Zeichenkettenvergleich.
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes as $attribute) {
                self::assertFalse(str_starts_with(strtolower($attribute->name), 'on'), 'Unexpected event handler: ' . $attribute->name);
                self::assertNotSame('data-x', $attribute->name);
                self::assertNotSame('autofocus', $attribute->name);
            }
        }
    }
}
