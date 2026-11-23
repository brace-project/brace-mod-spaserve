<?php

declare(strict_types=1);

namespace Brace\SpaServe\Tests;

use Brace\SpaServe\Html\HtmlGenerator;
use Brace\SpaServe\Html\ViteAutoHtml;
use PHPUnit\Framework\TestCase;

// Trusted head configuration is rendered consistently without changing default output.
final class HtmlHeadTest extends TestCase
{
    public function test_trusted_head_html_is_inside_head_in_all_modes(): void
    {
        $head = '<link rel="icon" type="image/png" href="assets/pagebuilder-logo.png">';
        foreach ([
            new HtmlGenerator(headHtml: $head, baseHref: '/builder/'),
            new ViteAutoHtml(development: false, url_prefix: '/builder/', headHtml: $head),
            new ViteAutoHtml(development: true, url_prefix: '/builder/', headHtml: $head),
        ] as $generator) {
            $html = $generator->render();
            self::assertStringContainsString('<base href="/builder/">', $html);
            self::assertSame(1, substr_count($html, $head));
            self::assertStringContainsString($head, explode('</head>', $html, 2)[0]);
        }
    }

    // Omitting the new optional argument preserves the existing HTML byte for byte.
    public function test_omitted_head_html_preserves_output(): void
    {
        self::assertSame(
            (new HtmlGenerator())->render(),
            (new HtmlGenerator(headHtml: null))->render(),
        );
        foreach ([false, true] as $development) {
            self::assertSame(
                (new ViteAutoHtml(development: $development))->render(),
                (new ViteAutoHtml(development: $development, headHtml: null))->render(),
            );
        }
    }
}
