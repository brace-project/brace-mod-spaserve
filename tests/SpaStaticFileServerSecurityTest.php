<?php

declare(strict_types=1);

namespace Brace\SpaServe\Tests;

use Brace\Core\BraceApp;
use Brace\Core\EnvironmentType;
use Brace\Mod\Request\Zend\BraceRequestLaminasModule;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SpaStaticFileServerSecurityTest extends TestCase
{
    private string $root;
    private const SECRET = 'PRIVATE-OUTSIDE-BUNDLE-4d22aeb8';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/spaserve-security-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/dist/assets', 0700, true);
        mkdir($this->root . '/dist-private', 0700);
        file_put_contents($this->root . '/secret.txt', self::SECRET);
        file_put_contents($this->root . '/dist-private/secret.txt', self::SECRET);
        file_put_contents($this->root . '/dist/assets/app.js', 'console.log("public");');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public static function forbiddenPaths(): iterable
    {
        $paths = [
            '../secret.txt',
            'assets/../../secret.txt',
            '%2e%2e/secret.txt',
            'assets/%2e%2e/%2e%2e/secret.txt',
            '%2e%2e%2fsecret.txt',
            '%252e%252e%252fsecret.txt',
            '..%5csecret.txt',
            'assets%5c..%5c..%5csecret.txt',
            'assets/..\\..\\secret.txt',
            'assets/app.js%00/../../secret.txt',
            '../dist-private/secret.txt',
            '%2e%2e/dist-private/secret.txt',
            'assets/missing.js',
        ];
        foreach (['', '/demo'] as $prefix) {
            foreach ($paths as $path) {
                yield $prefix . '/' . $path => [$prefix, $path];
            }
        }
    }

    #[DataProvider('forbiddenPaths')]
    public function test_rejects_unsafe_or_missing_assets(string $prefix, string $path): void
    {
        $response = $this->request($prefix, $path);
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString(self::SECRET, (string) $response->getBody());
    }

    public function test_rejects_symlink_escapes_including_similarly_named_sibling(): void
    {
        if (!function_exists('symlink')) {
            self::markTestSkipped('Symlinks are unavailable on this platform.');
        }
        self::assertTrue(symlink($this->root . '/secret.txt', $this->root . '/dist/assets/file.txt'));
        self::assertTrue(symlink($this->root . '/dist-private', $this->root . '/dist/assets/sibling'));
        foreach (['', '/demo'] as $prefix) {
            foreach (['assets/file.txt', 'assets/sibling/secret.txt'] as $path) {
                $response = $this->request($prefix, $path);
                self::assertSame(404, $response->getStatusCode(), $prefix . '/' . $path);
                self::assertStringNotContainsString(self::SECRET, (string) $response->getBody());
            }
        }
    }

    public function test_serves_regular_build_files_with_and_without_prefix(): void
    {
        foreach (['', '/demo'] as $prefix) {
            $response = $this->request($prefix, 'assets/app.js');
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('console.log("public");', (string) $response->getBody());
        }
    }

    private function request(string $prefix, string $path): ResponseInterface
    {
        $app = new BraceApp(EnvironmentType::PRODUCTION, url_prefix: $prefix);
        $app->addModule(new BraceRequestLaminasModule());
        $middleware = new SpaStaticFileServerMw($this->root . '/dist', new ViteAutoHtml());
        $middleware->_setApp($app);
        $fallback = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new EmptyResponse(418);
            }
        };
        return $middleware->process(new ServerRequest(uri: $prefix . '/' . $path), $fallback);
    }

    private function removeTree(string $path): void
    {
        // Never follow fixture symlinks while cleaning up.
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
