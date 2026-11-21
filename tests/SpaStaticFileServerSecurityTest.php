<?php

declare(strict_types=1);

namespace Brace\SpaServe\Tests;

use Brace\Core\BraceApp;
use Brace\Core\EnvironmentType;
use Brace\Mod\Request\Zend\BraceRequestLaminasModule;
use Brace\SpaServe\Html\HtmlGenerator;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use Phore\FileSystem\Exception\FilesystemException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Security-critical: read AGENTS.md before changing tests or their execution. */
final class SpaStaticFileServerSecurityTest extends TestCase
{
    private string $root;
    private const SECRET = 'PRIVATE-OUTSIDE-BUNDLE-4d22aeb8';
    private const PUBLIC_JS = 'console.log("public");';
    private const SHELL = 'SECURITY-TEST-SPA-SHELL';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/spaserve-security-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/dist/assets/dist', 0700, true));
        self::assertTrue(mkdir($this->root . '/dist-private', 0700));
        file_put_contents($this->root . '/secret.txt', self::SECRET);
        file_put_contents($this->root . '/dist-private/secret.txt', self::SECRET);
        file_put_contents($this->root . '/dist/assets/app.js', self::PUBLIC_JS);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeTree($this->root);
        }
    }

    public static function forbiddenPaths(): iterable
    {
        // Bestehende Regressionen bleiben mit identischer 404-Erwartung erhalten.
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
        $this->assertFailure($response);
    }

    public static function malformedPaths(): iterable
    {
        $paths = [
            'upper-case traversal' => '%2E%2E/private',
            'mixed traversal one' => '.%2e/private',
            'mixed traversal two' => '%2e./private',
            'literal extensionless traversal' => '../private',
            'encoded extensionless traversal' => '%2e%2e/private',
            'terminal traversal' => 'assets/..',
            'single dot' => 'assets/./app.js',
            'encoded single dot' => 'assets/%2E/app.js',
            'double encoding' => '%252e%252e/private',
            'triple encoding' => '%25252e%25252e/private',
            'double encoded separator' => 'assets%252fapp.js',
            'double encoded NUL' => 'assets/app.js%2500',
            'bare percent' => 'assets/%',
            'short escape' => 'assets/%2',
            'invalid hex escape' => 'assets/%GG',
            'partly valid escape' => 'assets/%2G',
            'literal NUL' => "assets/app.js\0",
            'encoded NUL' => 'assets/app.js%00',
            'literal CR' => "assets/app.js\r",
            'literal LF' => "assets/app.js\n",
            'literal tab' => "assets/\tapp.js",
            'literal DEL' => "assets/app.js\x7f",
            'encoded CRLF' => 'assets/app.js%0d%0aX-Injected:yes',
            'encoded tab' => 'assets/%09app.js',
            'encoded DEL' => 'assets/app.js%7f',
            'raw invalid UTF-8' => "assets/\xc0\xae/private",
            'encoded overlong dot' => 'assets/%c0%ae%c0%ae/private',
            'encoded overlong slash' => 'assets%c0%af..%c0%afprivate',
            'UTF-8 surrogate' => 'assets/%ed%a0%80',
            'truncated UTF-8' => 'assets/%e2%82',
            'plain backslash' => 'assets\\app.js',
            'encoded backslash' => 'assets%5capp.js',
            'mixed separators' => 'assets\\../private',
            'Windows drive' => 'C:/Windows/win.ini',
            'NTFS alternate stream' => 'assets/app.js:secret',
            'encoded colon' => 'assets/app.js%3asecret',
            'stream wrapper' => 'php://filter/resource=secret.txt',
            'raw query delimiter' => 'assets/app.js?raw',
            'encoded query delimiter' => 'assets/app.js%3fraw',
            'raw fragment delimiter' => 'assets/app.js#fragment',
            'encoded fragment delimiter' => 'assets/app.js%23fragment',
            'repeated separator' => 'assets//app.js',
            'encoded repeated separator' => 'assets%2f%2fapp.js',
            'leading repeated separator' => '/assets/app.js',
            'UNC network path' => '/nonexistent.invalid/share/secret',
            'encoded UNC network path' => '%2fnonexistent.invalid/share/secret',
            'UNC backslashes' => '\\\\nonexistent.invalid\\share\\secret',
            'encoded UNC backslashes' => '%5c%5cnonexistent.invalid%5cshare%5csecret',
            'Windows extended prefix' => '\\\\?\\C:\\secret',
            'encoded Windows extended prefix' => '%5c%5c%3f%5cC%3a%5csecret',
            'trailing directory dot' => 'assets./app.js',
            'trailing directory space' => 'assets /app.js',
            'trailing filename dot' => 'assets/app.js.',
            'trailing filename space' => 'assets/app.js%20',
            'device CON' => 'CON',
            'device PRN extension' => 'assets/prn.txt',
            'device AUX' => 'assets/AUX',
            'device NUL extension' => 'assets/NuL.js',
            'device leading space' => 'assets/%20CON',
            'device space before extension' => 'assets/CON%20.txt',
            'device surrounding spaces' => 'assets/%20AUX%20.txt',
            'device COM1' => 'assets/COM1',
            'device COM9 extension' => 'assets/com9.js',
            'device LPT1' => 'assets/LPT1',
            'device LPT9 extension' => 'assets/lpt9.css',
            'device CONIN' => 'assets/CONIN$',
            'device CONOUT extension' => 'assets/conout$.txt',
            'device superscript COM' => 'assets/COM¹.js',
            'device superscript LPT' => 'assets/LPT²',
            'malformed excluded API path' => 'api/%2e%2e/assets/app.js',
        ];

        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                foreach ($paths as $name => $path) {
                    yield $name . ' ' . ($prefix ?: '/') . ($development ? ' dev' : ' prod')
                        => [$prefix, $path, $development];
                }
            }
        }
    }

    #[DataProvider('malformedPaths')]
    public function test_rejects_malformed_upstream_paths_before_routing_or_io(
        string $prefix,
        string $path,
        bool $development,
    ): void {
        $middleware = $this->middleware($prefix, development: $development);
        $request = $this->rawRequest($prefix . '/' . $path);
        $this->assertFailure($this->process($middleware, $request));
    }

    public function test_rejects_non_absolute_paths_from_a_broken_http_adapter(): void
    {
        $middleware = $this->middleware();
        foreach (['assets/app.js', 'https://attacker.example/assets/app.js', '*', '\\\\nonexistent.invalid\\share\\secret', '\\\\?\\C:\\secret'] as $path) {
            $this->assertFailure($this->process($middleware, $this->rawRequest($path)));
        }
    }

    public function test_unsafe_paths_are_rejected_before_accessing_an_unavailable_bundle(): void
    {
        $middleware = $this->middleware();
        self::assertTrue(rename($this->root . '/dist', $this->root . '/offline-dist'));
        clearstatcache(true);
        self::assertFalse(is_dir($this->root . '/dist'));
        foreach (['/%2e%2e/private', '//nonexistent.invalid/share/secret', '/%5c%5cnonexistent.invalid/share/secret', '/php://filter/resource=secret'] as $path) {
            // Ungültige Eingaben bleiben 404 statt den späteren I/O-Fehler auszulösen.
            $this->assertFailure($this->process($middleware, $this->rawRequest($path)));
        }
    }

    public static function privateFiles(): iterable
    {
        $paths = [
            '.env', '.env.production', '.git/config', '.svn/entries',
            'assets/.credentials', 'assets/.git/config',
            'config.php', 'config.PHP', 'config.php8', 'config.php.jpg',
            'assets/template.phtml', 'assets/template.pht', 'archive.phar',
            'config.inc', 'credentials.env', 'settings.ini', 'server.log',
            'dump.sql', 'database.sqlite', 'database.sqlite3', 'data.db',
            'app.js.bak', 'app.js.backup', 'app.js.old', 'app.js.orig',
            'app.js.save', 'app.js.swp', 'app.js.swo', 'private.pem',
            'server.key', 'app.js~', 'composer.json', 'COMPOSER.JSON',
            'composer.lock', 'package.json', 'package-lock.json',
            'pnpm-lock.yaml', 'yarn.lock', 'assets/composer.json',
            'vendor/library/client.js', 'Vendor/library/client.js',
            'node_modules/library/client.js',
        ];
        foreach ($paths as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('privateFiles')]
    public function test_never_serves_existing_private_files_inside_the_bundle(string $path): void
    {
        $publicPath = str_starts_with($path, 'assets/') ? $path : 'assets/' . $path;
        $this->writeBundleFile($publicPath, self::SECRET);
        foreach (['', '/demo'] as $prefix) {
            $this->assertFailure($this->request($prefix, $publicPath));
            // Prozentkodierung darf die Dateiklassifikation nicht umgehen.
            $encoded = implode('/', array_map(
                static fn (string $segment): string => '%' . bin2hex($segment[0]) . substr($segment, 1),
                explode('/', $publicPath),
            ));
            $this->assertFailure($this->request($prefix, $encoded));
        }
    }

    public function test_rejects_symlink_escapes_including_similarly_named_sibling(): void
    {
        self::assertTrue(function_exists('symlink'), 'Security CI must support symlink fixtures; do not skip this test.');
        self::assertTrue(symlink($this->root . '/secret.txt', $this->root . '/dist/assets/file.txt'));
        self::assertTrue(symlink($this->root . '/dist-private', $this->root . '/dist/assets/sibling'));
        self::assertTrue(symlink($this->root . '/dist/assets/file.txt', $this->root . '/dist/assets/chain.txt'));
        self::assertTrue(symlink($this->root . '/secret.txt', $this->root . '/dist/assets/private'));

        foreach (['', '/demo'] as $prefix) {
            foreach (['assets/file.txt', 'assets/sibling/secret.txt', 'assets/chain.txt', 'assets/private'] as $path) {
                $response = $this->request($prefix, $path);
                self::assertSame(404, $response->getStatusCode(), $prefix . '/' . $path);
                self::assertStringNotContainsString(self::SECRET, (string) $response->getBody());
                $this->assertFailure($response);
            }
        }
    }

    public function test_rejects_broken_and_cyclic_symlinks_without_diagnostics(): void
    {
        self::assertTrue(symlink($this->root . '/missing', $this->root . '/dist/assets/broken'));
        self::assertTrue(symlink('loop-b', $this->root . '/dist/assets/loop-a'));
        self::assertTrue(symlink('loop-a', $this->root . '/dist/assets/loop-b'));
        foreach (['', '/demo'] as $prefix) {
            foreach (['assets/broken', 'assets/loop-a'] as $path) {
                $this->assertFailure($this->request($prefix, $path));
            }
        }
    }

    public function test_symlink_aliases_cannot_publish_hidden_or_server_source_files(): void
    {
        $targets = ['.env', '.private/token.txt', 'private/config.php'];
        foreach ($targets as $index => $target) {
            $this->writeBundleFile('assets/' . $target, self::SECRET);
            $alias = 'assets/published-' . $index . '.js';
            self::assertTrue(symlink($this->root . '/dist/assets/' . $target, $this->root . '/dist/' . $alias));
            foreach (['', '/demo'] as $prefix) {
                $this->assertFailure($this->request($prefix, $alias));
            }
        }
    }

    public function test_permits_internal_symlinks_to_public_files_and_directories(): void
    {
        self::assertTrue(symlink('app.js', $this->root . '/dist/assets/alias.js'));
        self::assertTrue(symlink('.', $this->root . '/dist/assets/public'));
        foreach (['', '/demo'] as $prefix) {
            foreach (['assets/alias.js', 'assets/public/app.js'] as $path) {
                $response = $this->request($prefix, $path);
                self::assertSame(200, $response->getStatusCode());
                self::assertSame(self::PUBLIC_JS, (string) $response->getBody());
                self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            }
        }
    }

    public function test_serves_regular_build_files_with_and_without_prefix(): void
    {
        foreach (['', '/demo'] as $prefix) {
            $response = $this->request($prefix, 'assets/app.js');
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('console.log("public");', (string) $response->getBody());
            self::assertSame('text/javascript; charset=utf-8', $response->getHeaderLine('Content-Type'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        }
    }

    public function test_public_assets_are_served_in_both_environments_outside_the_spa_mount(): void
    {
        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                $middleware = $this->middleware($prefix, development: $development, mount: $prefix . '/e');
                foreach (['GET', 'HEAD'] as $method) {
                    $response = $this->process($middleware, new ServerRequest(
                        uri: $prefix . '/assets/app.js', method: $method,
                    ));
                    self::assertSame(200, $response->getStatusCode());
                    self::assertSame($method === 'HEAD' ? '' : self::PUBLIC_JS, (string) $response->getBody());
                    self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
                }
                $this->assertFailure($this->process($middleware, new ServerRequest(
                    uri: $prefix . '/assets/%2e%2e/secret.txt', method: 'GET',
                )));
            }
        }
    }

    public static function publicFiles(): iterable
    {
        yield 'CSS' => ['assets/app.css', 'assets/app.css', 'body { color: red; }', 'text/css; charset=utf-8'];
        yield 'JSON' => ['assets/data.json', 'assets/data.json', '{"ok":true}', 'application/json; charset=utf-8'];
        yield 'published source map' => ['assets/app.js.map', 'assets/app.js.map', '{"version":3}', 'application/json; charset=utf-8'];
        yield 'uppercase JS' => ['assets/UPPER.JS', 'assets/UPPER.JS', self::PUBLIC_JS, 'text/javascript; charset=utf-8'];
        yield 'unknown type cannot be sniffed' => ['assets/payload.unknown', 'assets/payload.unknown', '<script>alert(1)</script>', 'application/octet-stream'];
        yield 'encoded space' => ['assets/my file.js', 'assets/my%20file.js', self::PUBLIC_JS, 'text/javascript; charset=utf-8'];
        yield 'UTF-8 filename' => ['assets/grüße.js', 'assets/gr%C3%BC%C3%9Fe.js', self::PUBLIC_JS, 'text/javascript; charset=utf-8'];
        yield 'encoded slash and ASCII' => ['assets/app.js', 'a%73sets%2fapp.js', self::PUBLIC_JS, 'text/javascript; charset=utf-8'];
        yield 'literal percent' => ['assets/100%.txt', 'assets/100%25.txt', 'public percent', 'text/plain; charset=utf-8'];
        yield 'plus stays plus' => ['assets/a+b.txt', 'assets/a+b.txt', 'public plus', 'text/plain; charset=utf-8'];
    }

    #[DataProvider('publicFiles')]
    public function test_valid_assets_keep_content_and_explicit_mime_type(
        string $file,
        string $requestPath,
        string $content,
        string $mime,
    ): void {
        $this->writeBundleFile($file, $content);
        foreach (['', '/demo'] as $prefix) {
            $response = $this->request($prefix, $requestPath);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame($content, (string) $response->getBody());
            self::assertSame($mime, $response->getHeaderLine('Content-Type'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        }
    }

    public static function missingAssets(): iterable
    {
        foreach (['assets', 'assets/', 'assets/missing', 'a%73sets/missing', 'assets%2fmissing', 'missing.js', 'missing%2ejs', 'missing.css', 'missing.js.map'] as $path) {
            foreach ([false, true] as $development) {
                yield $path . ($development ? ' dev' : ' prod') => [$path, $development];
            }
        }
    }

    #[DataProvider('missingAssets')]
    public function test_missing_assets_never_fall_back_to_html(string $path, bool $development): void
    {
        foreach (['', '/demo'] as $prefix) {
            $this->assertFailure($this->request($prefix, $path, development: $development));
        }
    }

    public function test_existing_directories_are_never_listed_or_returned_as_navigation(): void
    {
        $this->writeBundleFile('assets/documents/secret.txt', self::SECRET);
        foreach (['', '/demo'] as $prefix) {
            foreach (['assets/documents', 'assets/documents/', 'assets', 'assets/'] as $path) {
                $this->assertFailure($this->request($prefix, $path));
            }
        }
    }

    public function test_navigation_keeps_working_in_both_environments_and_mounts(): void
    {
        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                foreach (['', 'dashboard', 'patients/42', 'gr%C3%BC%C3%9Fe', 'apiary'] as $path) {
                    $response = $this->request($prefix, $path, development: $development);
                    self::assertSame(200, $response->getStatusCode());
                    self::assertStringContainsString(self::SHELL, (string) $response->getBody());
                    self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
                    self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
                }
            }
        }
    }

    public function test_empty_uri_path_and_exact_mount_remain_valid_navigation(): void
    {
        $root = $this->process($this->middleware(), $this->rawRequest(''));
        self::assertSame(200, $root->getStatusCode());
        self::assertStringContainsString(self::SHELL, (string) $root->getBody());
        $mounted = $this->process($this->middleware('/demo'), new ServerRequest(uri: '/demo'));
        self::assertSame(200, $mounted->getStatusCode());
        self::assertStringContainsString(self::SHELL, (string) $mounted->getBody());
    }

    public function test_unsupported_methods_do_not_return_assets_or_shells(): void
    {
        foreach (['', '/demo'] as $prefix) {
            foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'TRACE', 'CONNECT'] as $method) {
                foreach (['assets/app.js', 'dashboard'] as $path) {
                    $response = $this->request($prefix, $path, $method);
                    $this->assertFailure($response, 405);
                    self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
                    self::assertStringNotContainsString(self::PUBLIC_JS, (string) $response->getBody());
                }
            }
        }
    }

    public function test_head_preserves_status_and_content_type_without_any_body(): void
    {
        foreach (['', '/demo'] as $prefix) {
            foreach (['assets/app.js', 'dashboard', 'assets/missing.js', '%2e%2e/private', '.env'] as $path) {
                $get = $this->request($prefix, $path);
                $head = $this->request($prefix, $path, 'HEAD');
                self::assertSame($get->getStatusCode(), $head->getStatusCode());
                self::assertSame($get->getHeaderLine('Content-Type'), $head->getHeaderLine('Content-Type'));
                self::assertSame('nosniff', $head->getHeaderLine('X-Content-Type-Options'));
                self::assertSame('', (string) $head->getBody());
                if ($head->getStatusCode() >= 400) {
                    $this->assertFailure($head, $get->getStatusCode(), true);
                }
            }
        }
    }

    public function test_canonical_api_exclusions_delegate_the_original_request_unchanged(): void
    {
        // Eine gleichnamige Datei darf das ausgeschlossene Backend nicht ersetzen.
        $this->writeBundleFile('api/data.json', self::SECRET);
        $paths = [
            'api', 'api/data.json', '%61pi/data.json', 'api%2fdata.json',
            'api/reports/2026-11-17T17:00:00', 'api/users/con',
            'api/tags/release.', 'api/.env', 'api/' . str_repeat('a', 256),
        ];
        foreach (['', '/demo'] as $prefix) {
            foreach (['GET', 'POST', 'HEAD'] as $method) {
                foreach ($paths as $path) {
                    $request = new ServerRequest(uri: $prefix . '/' . $path, method: $method);
                    $handler = new class implements RequestHandlerInterface {
                        public ?ServerRequestInterface $received = null;

                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            $this->received = $request;
                            return new EmptyResponse(418);
                        }
                    };
                    $response = $this->middleware($prefix)->process($request, $handler);
                    self::assertSame($request, $handler->received);
                    self::assertSame(418, $response->getStatusCode());
                }
            }
        }
    }

    public function test_mount_comparison_uses_canonical_complete_path_segments(): void
    {
        $middleware = $this->middleware('/demo');
        foreach (['/demonstration/assets/app.js', '/demo-other/assets/app.js', '/assets/app.js'] as $path) {
            $this->assertFailure($this->process($middleware, new ServerRequest(uri: $path)));
        }
        $response = $this->process($middleware, new ServerRequest(uri: '/%64emo/assets/app.js'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::PUBLIC_JS, (string) $response->getBody());

        // Ein expliziter Mount überschreibt ausschließlich die SPA-Zuordnung.
        $override = $this->middleware('/demo', mount: '/other');
        $response = $this->process($override, new ServerRequest(uri: '/demo/assets/app.js'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::PUBLIC_JS, (string) $response->getBody());
        self::assertSame('text/javascript; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $response = $this->process($override, new ServerRequest(uri: '/other/assets/app.js'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::PUBLIC_JS, (string) $response->getBody());
    }

    public function test_outside_mount_errors_do_not_reflect_attacker_markup(): void
    {
        $path = '/outside/"><script>alert(1)</script>';
        $response = $this->process($this->middleware('/demo'), $this->rawRequest($path));
        $this->assertFailure($response);
        self::assertStringNotContainsString($path, (string) $response->getBody());
    }

    public function test_query_headers_and_server_parameters_cannot_change_the_file_authority(): void
    {
        $request = new ServerRequest(
            serverParams: [
                'DOCUMENT_ROOT' => $this->root,
                'REQUEST_URI' => '/../secret.txt',
                'PATH_INFO' => '/../secret.txt',
                'ORIG_PATH_INFO' => '/../secret.txt',
            ],
            uri: '/demo/assets/app.js?import&raw&url=../../secret.txt',
            method: 'GET',
            headers: [
                'Host' => 'attacker.example',
                'X-Original-URL' => '/../secret.txt',
                'X-Rewrite-URL' => '/../secret.txt',
                'X-Forwarded-Prefix' => '/../',
                'X-Forwarded-Host' => 'attacker.example',
                'Range' => 'bytes=-999999999999999999999999999999999999',
                'Accept' => 'text/html',
            ],
        );
        $response = $this->process($this->middleware('/demo'), $request);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::PUBLIC_JS, (string) $response->getBody());
        self::assertSame('text/javascript; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Location'));
        self::assertFalse($response->hasHeader('X-Injected'));
    }

    public function test_query_suffixes_never_bypass_a_private_file_denial(): void
    {
        $this->writeBundleFile('.env', self::SECRET);
        foreach (['', '/demo'] as $prefix) {
            foreach (['?raw', '?import&raw', '?raw??', '?url', '?download=1', '?x=.js'] as $query) {
                $this->assertFailure($this->request($prefix, '.env' . $query));
            }
        }
    }

    public function test_development_serves_public_assets_without_exposing_private_frontend_files(): void
    {
        $this->writeBundleFile('download', self::SECRET);
        foreach (['', '/demo'] as $prefix) {
            $response = $this->request($prefix, 'assets/app.js', development: true);
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(self::PUBLIC_JS, (string) $response->getBody());
            self::assertSame('text/javascript; charset=utf-8', $response->getHeaderLine('Content-Type'));
            self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            $response = $this->request($prefix, 'download', development: true);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString(self::SHELL, (string) $response->getBody());
            self::assertStringNotContainsString(self::SECRET, (string) $response->getBody());
        }
    }

    public function test_development_can_render_without_a_production_bundle(): void
    {
        $middleware = $this->middleware(development: true, bundleDir: $this->root . '/missing-dist');
        $response = $this->process($middleware, new ServerRequest(uri: '/dashboard'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(self::SHELL, (string) $response->getBody());
    }

    public function test_missing_production_bundle_fails_during_bootstrap_with_internal_context(): void
    {
        $this->expectException(FilesystemException::class);
        $this->expectExceptionMessage($this->root . '/dist/assets/missing-dist');
        $this->middleware(bundleDir: $this->root . '/missing-dist');
    }

    public function test_a_removed_runtime_bundle_returns_a_safe_error_instead_of_html(): void
    {
        $middleware = $this->middleware();
        self::assertTrue(rename($this->root . '/dist', $this->root . '/offline-dist'));
        clearstatcache(true);
        foreach (['/', '/dashboard', '/assets/app.js'] as $path) {
            $this->assertFailure($this->process($middleware, new ServerRequest(uri: $path)), 500);
        }
    }

    public function test_replacing_the_bound_root_does_not_publish_the_replacement(): void
    {
        $middleware = $this->middleware();
        self::assertTrue(rename($this->root . '/dist', $this->root . '/original-dist'));
        $this->writeBundleFile('assets/app.js', self::SECRET);
        clearstatcache(true);
        $this->assertFailure($this->process($middleware, new ServerRequest(uri: '/assets/app.js')), 500);
    }

    public function test_unreadable_assets_return_generic_errors_without_filesystem_details(): void
    {
        $path = $this->writeBundleFile('assets/unreadable.txt', self::SECRET);
        self::assertTrue(chmod($path, 0000));
        clearstatcache(true, $path);
        try {
            self::assertFalse(is_readable($path), 'Run security tests as an unprivileged user; this fixture must be unreadable.');
            $this->assertFailure($this->request('', 'assets/unreadable.txt'), 500);
            $this->assertFailure($this->request('', 'assets/unreadable.txt', 'HEAD'), 500, true);
        } finally {
            chmod($path, 0600);
        }
    }

    public function test_renderer_exceptions_and_errors_are_not_disclosed(): void
    {
        foreach ([\RuntimeException::class, \Error::class] as $errorClass) {
            $failure = new $errorClass(self::SECRET . ' at ' . $this->root . '/config.php');
            $html = new class($failure) extends HtmlGenerator {
                public function __construct(private \Throwable $failure)
                {
                    parent::__construct();
                }

                public function render(): string
                {
                    throw $this->failure;
                }
            };
            $middleware = $this->middleware(html: $html);
            $this->assertFailure($this->process($middleware, new ServerRequest(uri: '/dashboard')), 500);
            $this->assertFailure($this->process($middleware, new ServerRequest(uri: '/dashboard', method: 'HEAD')), 500, true);
        }
    }

    public function test_path_length_limits_keep_the_valid_boundary_and_reject_excess(): void
    {
        $filename = str_repeat('a', 252) . '.js';
        $this->writeBundleFile('assets/' . $filename, self::PUBLIC_JS);
        $response = $this->request('', 'assets/' . $filename);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::PUBLIC_JS, (string) $response->getBody());
        $this->assertFailure($this->request('', str_repeat('a', 256)));

        // Das Limit gilt für die Eingabe vor dem Dekodieren und vor jedem I/O.
        $tooLong = '/' . str_repeat('a/', 4096);
        self::assertSame(8193, strlen($tooLong));
        foreach (['GET', 'HEAD'] as $method) {
            $response = $this->process($this->middleware(), $this->rawRequest($tooLong, $method));
            $this->assertFailure($response, 414, $method === 'HEAD');
        }
    }

    public static function missingAssetMessages(): iterable
    {
        // Nur der URL-Verzeichnisteil darf in der öffentlichen Diagnose erscheinen.
        $cases = [
            'CSS' => ['assets/missing.css', '/assets/', ' CSS requested.'],
            'uppercase CSS' => ['assets/missing.CSS', '/assets/', ' CSS requested.'],
            'JavaScript' => ['assets/missing.js', '/assets/', ' JavaScript requested.'],
            'module JavaScript' => ['assets/missing.mjs', '/assets/', ' JavaScript requested.'],
            'CommonJS' => ['assets/missing.cjs', '/assets/', ' JavaScript requested.'],
            'extensionless asset' => ['assets/missing', '/assets/', ''],
            'asset directory' => ['assets', '/assets/', ''],
            'asset directory slash' => ['assets/', '/assets/', ''],
            'root CSS' => ['style.css', '/', ' CSS requested.'],
            'other public extension' => ['images/missing.png', '/images/', ''],
            'nested JavaScript' => ['assets/nested/missing.js', '/assets/nested/', ' JavaScript requested.'],
            'encoded space' => ['assets/my%20folder/missing.css', '/assets/my%20folder/', ' CSS requested.'],
            'encoded Unicode' => ['assets/gr%C3%BC%C3%9Fe/missing.css', '/assets/gr%C3%BC%C3%9Fe/', ' CSS requested.'],
            'literal percent directory' => ['assets/100%25/missing.js', '/assets/100%25/', ' JavaScript requested.'],
            'encoded markup directory' => [
                'assets/%3Cimg%20src=x%20onerror=alert(1)%3E/missing.css',
                '/assets/%3Cimg%20src%3Dx%20onerror%3Dalert%281%29%3E/',
                ' CSS requested.',
            ],
            'query is omitted' => ['assets/missing.css?token=' . self::SECRET, '/assets/', ' CSS requested.'],
            'filename markup is omitted' => ['assets/%3Cscript%3E.css', '/assets/', ' CSS requested.'],
        ];

        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                foreach ($cases as $name => [$path, $directory, $suffix]) {
                    yield $name . ' ' . ($prefix ?: '/') . ($development ? ' dev' : ' prod')
                        => [$prefix, $development, $path, $prefix . $directory, $suffix];
                }
            }
        }
    }

    #[DataProvider('missingAssetMessages')]
    public function test_missing_assets_report_only_the_encoded_public_url_directory(
        string $prefix,
        bool $development,
        string $path,
        string $directory,
        string $suffix,
    ): void {
        $headers = ['Accept' => 'text/html', 'Sec-Fetch-Dest' => 'document'];
        $response = $this->request($prefix, $path, development: $development, headers: $headers);
        $this->assertFailure($response);
        self::assertSame('File not Found (SPA-Serve Asset Path: ' . $directory . ').' . $suffix, (string) $response->getBody());
        $this->assertFallbackVary($response);

        $head = $this->request($prefix, $path, 'HEAD', $development, $headers);
        $this->assertFailure($head, 404, true);
        $this->assertFallbackVary($head);
    }

    public static function fallbackAcceptHeaders(): iterable
    {
        // Spezifität, Formatfehler und Asset-Präferenzen getrennt absichern.
        $cases = [
            'absent' => [null, null],
            'empty' => ['', ' HTML not accepted.'],
            'whitespace only' => [" \t ", ' HTML not accepted.'],
            'empty list' => [',, ,', ' HTML not accepted.'],
            'HTML' => ['text/html', null],
            'all wildcard' => ['*/*', null],
            'text wildcard' => ['text/*', null],
            'JSON only' => ['application/json', ' HTML not accepted.'],
            'XHTML is not HTML' => ['application/xhtml+xml', ' HTML not accepted.'],
            'browser navigation' => ['text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,*/*;q=0.8', null],
            'specific HTML denial' => ['text/html;q=0,*/*;q=1', ' HTML not accepted.'],
            'specific text denial' => ['text/*;q=0,*/*;q=1', ' HTML not accepted.'],
            'specific HTML acceptance' => ['*/*;q=0,text/html;q=1', null],
            'positive HTML overrides higher wildcard' => ['text/html;q=0.001,*/*;q=1', null],
            'three decimal quality' => ['text/html;q=0.999', null],
            'quality one with decimals' => ['text/html;q=1.000', null],
            'quality one empty fraction' => ['text/html;q=1.', null],
            'quality zero empty fraction' => ['text/html;q=0.', ' HTML not accepted.'],
            'case insensitive type and q' => ['TEXT/HTML;Q=1', null],
            'UTF8 charset' => ['text/html;charset=utf-8', null],
            'quoted case insensitive charset' => ['TeXt/HtMl;ChArSeT="UTF-8";Q=0.5', null],
            'charset after q' => ['text/html;q=0.8;charset=UTF-8', null],
            'OWS around semicolons and member' => ['text/html ; charset=utf-8 ; q=1 ', null],
            'space before q equals' => ['text/html;q = 1', ' Invalid Accept header.'],
            'space after q equals' => ['text/html;q= 1', ' Invalid Accept header.'],
            'space before charset equals' => ['text/html;charset = utf-8', ' Invalid Accept header.'],
            'wrong charset' => ['text/html;charset=iso-8859-1', ' HTML not accepted.'],
            'wrong charset permits matching wildcard' => ['text/html;charset=iso-8859-1,*/*;q=0.5', null],
            'specific charset denial' => ['text/html;q=1,text/html;charset=utf-8;q=0', ' HTML not accepted.'],
            'specific charset denies wildcard too' => ['text/html;charset=utf-8;q=0,text/html;q=1,*/*;q=1', ' HTML not accepted.'],
            'specific charset acceptance' => ['text/html;charset=utf-8;q=0.5,text/html;q=0', null],
            'unmatched media parameter' => ['text/html;profile=custom', ' HTML not accepted.'],
            'unmatched parameter with wildcard' => ['text/html;profile=custom,*/*;q=0.5', null],
            'quoted comma and semicolon' => ['application/json;profile="a,b;c",text/html', null],
            'escaped quote in parameter' => ['application/json;profile="a\\"b,c",text/html', null],
            'ignored empty list members' => [',,text/html,, ,', null],
            'identical duplicate range' => ['text/html;q=0.5,text/html;q=0.5', null],
            'duplicate q parameter' => ['text/html;q=1;q=1', ' Invalid Accept header.'],
            'conflicting duplicate range' => ['text/html;q=1,text/html;q=0', ' Invalid Accept header.'],
            'case normalized conflicting range' => ['text/html;charset=utf-8;q=0.2,TEXT/HTML;CHARSET=UTF-8;q=1', ' Invalid Accept header.'],
            'missing leading zero' => ['text/html;q=.5', ' Invalid Accept header.'],
            'negative quality' => ['text/html;q=-0.1', ' Invalid Accept header.'],
            'quality exceeds one' => ['text/html;q=1.001', ' Invalid Accept header.'],
            'quality exceeds range' => ['text/html;q=2', ' Invalid Accept header.'],
            'too many quality decimals' => ['text/html;q=0.0000', ' Invalid Accept header.'],
            'quality exponent' => ['text/html;q=1e0', ' Invalid Accept header.'],
            'quality NaN' => ['text/html;q=NaN', ' Invalid Accept header.'],
            'empty quality' => ['text/html;q=', ' Invalid Accept header.'],
            'quoted quality' => ['text/html;q="1"', ' Invalid Accept header.'],
            'leading zero quality' => ['text/html;q=01', ' Invalid Accept header.'],
            'unterminated quoted parameter' => ['text/html;charset="utf-8', ' Invalid Accept header.'],
            'trailing parameter separator' => ['text/html;', ' Invalid Accept header.'],
            'missing subtype' => ['text', ' Invalid Accept header.'],
            'invalid wildcard position' => ['*/html', ' Invalid Accept header.'],
            'raw CRLF' => ["text/html\r\nX-Injected: " . self::SECRET, ' Invalid Accept header.'],
            'raw NUL' => ["text/html\0", ' Invalid Accept header.'],
            'raw DEL' => ["text/html\x7f", ' Invalid Accept header.'],
            'CSS only' => ['text/css', ' CSS requested.'],
            'JavaScript only' => ['application/javascript', ' JavaScript requested.'],
            'text JavaScript only' => ['text/javascript', ' JavaScript requested.'],
            'CSS outranks wildcard' => ['text/css,*/*;q=0.1', ' CSS requested.'],
            'JavaScript ties wildcard' => ['text/javascript,*/*', ' JavaScript requested.'],
            'CSS ties text wildcard' => ['text/css;q=0.5,text/*;q=0.5', ' CSS requested.'],
            'explicit HTML wins tie with CSS' => ['text/html,text/css', null],
            'explicit HTML wins tie with JavaScript' => ['text/html;q=0.5,application/javascript;q=0.5', null],
            'HTML preferred over CSS' => ['text/html;q=1,text/css;q=0.5', null],
            'CSS preferred over HTML' => ['text/html;q=0.5,text/css;q=1', ' CSS requested.'],
            'JavaScript preferred over HTML' => ['text/html;q=0.5,text/javascript;q=0.7', ' JavaScript requested.'],
            'CSS denial is not a resource hint' => ['text/css;q=0,*/*;q=1', null],
            'JavaScript denial is not a resource hint' => ['application/javascript;q=0,*/*;q=1', null],
            'zero qualities do not request CSS' => ['text/html;q=0,text/css;q=0', ' HTML not accepted.'],
            'invalid quality overrides CSS hint' => ['text/css,*/*;q=.1', ' Invalid Accept header.'],
        ];

        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                foreach ($cases as $name => [$accept, $suffix]) {
                    yield $name . ' ' . ($prefix ?: '/') . ($development ? ' dev' : ' prod')
                        => [$prefix, $development, $accept, $suffix];
                }
            }
        }
    }

    #[DataProvider('fallbackAcceptHeaders')]
    public function test_html_fallback_respects_accept_without_confusing_assets_with_navigation(
        string $prefix,
        bool $development,
        ?string $accept,
        ?string $suffix,
    ): void {
        $headers = $accept === null ? [] : ['Accept' => $accept];
        $request = $this->headerRequest($prefix . '/dashboard', $headers);
        $response = $this->process($this->middleware($prefix, development: $development), $request);
        $this->assertFallbackVary($response);
        if ($suffix === null) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
            self::assertStringContainsString(self::SHELL, (string) $response->getBody());
            return;
        }

        $this->assertFailure($response);
        self::assertSame('File not Found (SPA-Serve Asset Path: ' . $prefix . '/).' . $suffix, (string) $response->getBody());
    }

    public static function fetchDestinations(): iterable
    {
        // Fetch-Metadaten sind case-sensitive Tokens, keine beliebigen Zeichenketten.
        $cases = [
            'absent' => [null, null],
            'fetch empty token' => ['empty', null],
            'document' => ['document', null],
            'frame' => ['frame', null],
            'iframe' => ['iframe', null],
            'style' => ['style', ' CSS requested.'],
            'script' => ['script', ' JavaScript requested.'],
            'worker' => ['worker', ' JavaScript requested.'],
            'sharedworker' => ['sharedworker', ' JavaScript requested.'],
            'serviceworker' => ['serviceworker', ' JavaScript requested.'],
            'image' => ['image', ' Asset requested.'],
            'font' => ['font', ' Asset requested.'],
            'audio' => ['audio', ' Asset requested.'],
            'video' => ['video', ' Asset requested.'],
            'object' => ['object', ' Asset requested.'],
            'embed' => ['embed', ' Asset requested.'],
            'manifest' => ['manifest', ' Asset requested.'],
            'unknown' => ['unknown-destination', ' Asset requested.'],
            'present empty' => ['', ' Asset requested.'],
            'whitespace only' => [" \t ", ' Asset requested.'],
            'uppercase document' => ['DOCUMENT', ' Asset requested.'],
            'uppercase style' => ['STYLE', ' Asset requested.'],
            'quoted document' => ['"document"', ' Asset requested.'],
            'multiple tokens' => ['document, script', ' Asset requested.'],
            'duplicate document tokens' => ['document, document', ' Asset requested.'],
            'raw CRLF' => ["document\r\nX-Injected: " . self::SECRET, ' Asset requested.'],
            'NUL around document' => ["\0document\0", ' Asset requested.'],
            'CRLF around document' => ["\r\ndocument\r\n", ' Asset requested.'],
            'vertical tab around document' => ["\vdocument\v", ' Asset requested.'],
        ];

        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                foreach ($cases as $name => [$destination, $suffix]) {
                    yield $name . ' ' . ($prefix ?: '/') . ($development ? ' dev' : ' prod')
                        => [$prefix, $development, $destination, $suffix];
                }
            }
        }
    }

    #[DataProvider('fetchDestinations')]
    public function test_fetch_destination_cannot_turn_a_resource_request_into_html(
        string $prefix,
        bool $development,
        ?string $destination,
        ?string $suffix,
    ): void {
        $headers = ['Accept' => 'text/html'];
        if ($destination !== null) {
            $headers['Sec-Fetch-Dest'] = $destination;
        }
        $request = $this->headerRequest($prefix . '/dashboard', $headers);
        $response = $this->process($this->middleware($prefix, development: $development), $request);
        $this->assertFallbackVary($response);
        if ($suffix === null) {
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString(self::SHELL, (string) $response->getBody());
            return;
        }

        $this->assertFailure($response);
        self::assertSame('File not Found (SPA-Serve Asset Path: ' . $prefix . '/).' . $suffix, (string) $response->getBody());
    }

    public function test_accept_limits_count_raw_bytes_and_quote_aware_list_members(): void
    {
        $cases = [
            ['text/html' . str_repeat(' ', 8183), true],
            ['text/html' . str_repeat(' ', 8184), false],
            [implode(',', array_fill(0, 64, 'text/html')), true],
            [implode(',', array_fill(0, 65, 'text/html')), false],
            [str_repeat(',', 63) . 'text/html', true],
            [str_repeat(',', 64) . 'text/html', false],
            ['application/json;profile="' . str_repeat('x,', 70) . 'x",text/html', true],
        ];
        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                foreach ($cases as [$accept, $allowed]) {
                    $request = $this->headerRequest($prefix . '/dashboard', ['Accept' => $accept]);
                    $response = $this->process($this->middleware($prefix, development: $development), $request);
                    $this->assertFallbackVary($response);
                    if ($allowed) {
                        self::assertSame(200, $response->getStatusCode());
                        self::assertStringContainsString(self::SHELL, (string) $response->getBody());
                    } else {
                        $this->assertFailure($response);
                        self::assertSame('File not Found (SPA-Serve Asset Path: ' . $prefix . '/). Invalid Accept header.', (string) $response->getBody());
                    }
                }
            }
        }
    }

    public function test_multiple_accept_fields_are_evaluated_as_one_header(): void
    {
        foreach (['', '/demo'] as $prefix) {
            $accepted = new ServerRequest(
                uri: $prefix . '/dashboard',
                headers: ['Accept' => ['text/html', 'text/css']],
            );
            $response = $this->process($this->middleware($prefix), $accepted);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString(self::SHELL, (string) $response->getBody());
            $this->assertFallbackVary($response);

            $conflict = new ServerRequest(
                uri: $prefix . '/dashboard',
                headers: ['Accept' => ['text/html;q=1', 'text/html;q=0']],
            );
            $response = $this->process($this->middleware($prefix), $conflict);
            $this->assertFailure($response);
            self::assertSame('File not Found (SPA-Serve Asset Path: ' . $prefix . '/). Invalid Accept header.', (string) $response->getBody());
        }
    }

    public function test_existing_public_assets_ignore_navigation_negotiation(): void
    {
        foreach (['', '/demo'] as $prefix) {
            foreach ([
                ['Accept' => 'text/css', 'Sec-Fetch-Dest' => 'style'],
                ['Accept' => 'text/html;q=2', 'Sec-Fetch-Dest' => 'unknown'],
                ['Accept' => '', 'Sec-Fetch-Dest' => 'image'],
            ] as $headers) {
                $response = $this->request($prefix, 'assets/app.js', headers: $headers);
                self::assertSame(200, $response->getStatusCode());
                self::assertSame(self::PUBLIC_JS, (string) $response->getBody());
                self::assertSame('text/javascript; charset=utf-8', $response->getHeaderLine('Content-Type'));

                $head = $this->request($prefix, 'assets/app.js', 'HEAD', headers: $headers);
                self::assertSame(200, $head->getStatusCode());
                self::assertSame('', (string) $head->getBody());
            }
        }
    }

    public function test_missing_asset_type_precedes_conflicting_navigation_headers(): void
    {
        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                foreach ([
                    ['assets/missing.css', ' CSS requested.'],
                    ['assets/missing.js', ' JavaScript requested.'],
                    ['assets/missing', ''],
                ] as [$path, $suffix]) {
                    $response = $this->request($prefix, $path, development: $development, headers: [
                        'Accept' => 'text/html;q=2',
                        'Sec-Fetch-Dest' => 'image',
                    ]);
                    $this->assertFailure($response);
                    self::assertSame('File not Found (SPA-Serve Asset Path: ' . $prefix . '/assets/).' . $suffix, (string) $response->getBody());
                    $this->assertFallbackVary($response);
                }
            }
        }
    }

    public function test_extensionless_missing_assets_keep_clear_css_and_javascript_hints(): void
    {
        $cases = [
            [['Accept' => 'text/html', 'Sec-Fetch-Dest' => 'style'], ' CSS requested.'],
            [['Accept' => 'text/html', 'Sec-Fetch-Dest' => 'script'], ' JavaScript requested.'],
            [['Accept' => 'text/css,*/*;q=0.1'], ' CSS requested.'],
            [['Accept' => 'application/javascript,*/*;q=0.1'], ' JavaScript requested.'],
            [['Accept' => 'text/html,text/css'], ''],
        ];
        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                foreach ($cases as [$headers, $suffix]) {
                    $response = $this->request($prefix, 'assets/missing', development: $development, headers: $headers);
                    $this->assertFailure($response);
                    self::assertSame('File not Found (SPA-Serve Asset Path: ' . $prefix . '/assets/).' . $suffix, (string) $response->getBody());
                    $this->assertFallbackVary($response);
                }
            }
        }
    }

    public function test_negotiation_rejects_before_rendering_and_keeps_head_bodyless(): void
    {
        $html = new class extends HtmlGenerator {
            public function render(): string
            {
                throw new \RuntimeException('Rejected navigation reached rendering: ' . self::class);
            }
        };
        foreach (['', '/demo'] as $prefix) {
            $middleware = $this->middleware($prefix, html: $html);
            foreach ([
                ['Accept' => 'application/json'],
                ['Accept' => 'text/html;q=2'],
                ['Accept' => 'text/html', 'Sec-Fetch-Dest' => 'script'],
                ['Accept' => 'text/html', 'Sec-Fetch-Dest' => 'unknown'],
            ] as $headers) {
                foreach (['GET', 'HEAD'] as $method) {
                    $request = $this->headerRequest($prefix . '/dashboard', $headers, $method);
                    $response = $this->process($middleware, $request);
                    $this->assertFailure($response, 404, $method === 'HEAD');
                    $this->assertFallbackVary($response);
                }
            }
        }
    }

    public function test_negotiation_does_not_intercept_excluded_api_requests(): void
    {
        foreach (['', '/demo'] as $prefix) {
            $request = new ServerRequest(
                uri: $prefix . '/api/users',
                method: 'POST',
                headers: ['Accept' => 'text/html;q=2', 'Sec-Fetch-Dest' => 'script'],
            );
            $handler = new class implements RequestHandlerInterface {
                public ?ServerRequestInterface $received = null;

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->received = $request;
                    return new EmptyResponse(418);
                }
            };
            $response = $this->middleware($prefix)->process($request, $handler);
            self::assertSame($request, $handler->received);
            self::assertSame(418, $response->getStatusCode());
        }
    }

    public function test_private_and_malformed_paths_never_use_the_reflecting_asset_diagnostic(): void
    {
        foreach (['', '/demo'] as $prefix) {
            foreach (['.env', '%2e%2e/private', 'assets/%00secret', 'assets/%3fsecret'] as $path) {
                $response = $this->request($prefix, $path, headers: [
                    'Accept' => 'text/css',
                    'Sec-Fetch-Dest' => 'style',
                ]);
                $this->assertFailure($response);
                self::assertStringNotContainsString('SPA-Serve Asset Path:', (string) $response->getBody());
                self::assertStringNotContainsString($path, (string) $response->getBody());
            }
        }
    }

    public static function viteInternalRequests(): iterable
    {
        // Feste Vite-Endpunkte sind global und unter dem tatsächlichen Mount reserviert.
        $paths = [
            '/@vite/client',
            '/@vite/env',
            '/@id',
            '/@id/module',
            '/@id/@scope/package',
            '/@id/__x00__virtual-module',
            '/@fs',
            '/@fs/workspace/main.ts',
            '/%40vite/%63lient',
            '/%40vite/%65nv',
            '/%40id%2fmodule',
            '/%40fs/workspace/main.ts',
            '/@vite/client?token=' . self::SECRET . '&file=%2Fetc%2Fpasswd',
        ];
        foreach (['', '/demo'] as $prefix) {
            foreach (array_unique(['', $prefix]) as $requestPrefix) {
                foreach ([false, true] as $development) {
                    foreach (['GET', 'HEAD'] as $method) {
                        foreach ($paths as $path) {
                            yield ($prefix ?: '/') . ' -> ' . $requestPrefix . $path
                                . ($development ? ' dev ' : ' prod ') . $method
                                => [$prefix, $requestPrefix . $path, $development, $method];
                        }
                    }
                }
            }
        }
    }

    #[DataProvider('viteInternalRequests')]
    public function test_reserved_vite_resources_have_environment_specific_safe_responses(
        string $prefix,
        string $path,
        bool $development,
        string $method,
    ): void {
        $request = new ServerRequest(uri: $path, method: $method, headers: [
            'Host' => 'attacker.example',
            'Accept' => 'text/html;q=2',
            'Sec-Fetch-Dest' => 'script',
        ]);
        $response = $this->process($this->middleware($prefix, development: $development), $request);
        if ($development) {
            $this->assertViteDiagnostic($response, $method === 'HEAD');
            return;
        }
        $this->assertFailure($response, 404, $method === 'HEAD');
        self::assertStringNotContainsString('Vite development resource', (string) $response->getBody());
        self::assertStringNotContainsString('SPA-Serve Asset Path:', (string) $response->getBody());
    }

    public static function unsafeVitePaths(): iterable
    {
        // Die Vite-Diagnose darf bestehende Pfad- und Geheimnissperren nicht ersetzen.
        $paths = [
            '/@fs/../secret.txt',
            '/@fs/%2e%2e/secret.txt',
            '/@fs/.env',
            '/@fs/project/node_modules/package/index.js',
            '/@fs/project/vendor/package.js',
            '/@id/.private',
            '/@id/config.php',
            '/@id/module%00',
            '/@id%252fmodule',
            '/@fs/C:/secret.txt',
            '/@fs//etc/passwd',
            '/@fs/%5c%5cnonexistent.invalid/share',
            '/@fs/%00secret',
            '/@fs/%GG/secret',
            "/@fs/\xff",
        ];
        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                foreach ($paths as $index => $path) {
                    yield 'unsafe Vite ' . $index . ' ' . ($prefix ?: '/') . ($development ? ' dev' : ' prod')
                        => [$prefix, $prefix . $path, $development];
                }
            }
        }
    }

    #[DataProvider('unsafeVitePaths')]
    public function test_vite_candidates_keep_all_path_rejection_guarantees(
        string $prefix,
        string $path,
        bool $development,
    ): void {
        $response = $this->process($this->middleware($prefix, development: $development), $this->rawRequest($path));
        $this->assertFailure($response);
        self::assertStringNotContainsString('Vite development resource', (string) $response->getBody());
        self::assertStringNotContainsString('SPA-Serve Asset Path:', (string) $response->getBody());
    }

    public function test_vite_resources_do_not_accept_unsupported_http_methods(): void
    {
        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                $middleware = $this->middleware($prefix, development: $development);
                foreach (array_unique(['/@vite/client', $prefix . '/@id/module']) as $path) {
                    foreach (['POST', 'PUT', 'OPTIONS', 'TRACE'] as $method) {
                        $response = $this->process($middleware, new ServerRequest(uri: $path, method: $method));
                        $this->assertFailure($response, 405);
                        self::assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
                        self::assertStringNotContainsString('Vite development resource', (string) $response->getBody());
                    }
                }
            }
        }
    }

    public static function generatedViteResourceUrls(): iterable
    {
        // Browser normalisieren nur die angegebenen Dot-Segmente vor dem Request.
        $cases = [
            'default root URLs' => [
                '/@vite/client', '/src/main.ts',
                ['/@vite/client', '/src/main.ts'],
                ['/@vite/client', '/src/main.ts'],
            ],
            'relative URLs' => [
                'boot/client', 'boot/main.ts',
                ['{mount}/boot/client', '{mount}/boot/main.ts'],
                ['{mount}/boot/client', '{mount}/boot/main.ts'],
            ],
            'absolute custom URLs' => [
                '/custom-vite/client', '/custom-src/main.ts',
                ['/custom-vite/client', '/custom-src/main.ts'],
                ['/custom-vite/client', '/custom-src/main.ts'],
            ],
            'mixed root and relative URLs' => [
                'boot/client', '/entry/main.ts?build=one#entry',
                ['{mount}/boot/client', '/entry/main.ts?build=one#entry'],
                ['{mount}/boot/client', '/entry/main.ts?build=two'],
            ],
            'encoded relative URLs and suffixes' => [
                'boot/%63lient?token=' . self::SECRET . '#client', 'boot/main%2Ets?build=one#entry',
                ['{mount}/boot/%63lient?token=' . self::SECRET . '#client', '{mount}/boot/main%2Ets?build=one#entry'],
                ['{mount}/boot/%63lient?token=' . self::SECRET, '{mount}/boot/main.ts?build=two'],
            ],
            'relative dot segment' => [
                'boot/client', './src/main.ts',
                ['{mount}/boot/client', '{mount}/./src/main.ts'],
                ['{mount}/boot/client', '{mount}/src/main.ts'],
            ],
            'relative parent segment' => [
                'boot/client', '../boot.ts',
                ['{mount}/boot/client', '{mount}/../boot.ts'],
                ['{mount}/boot/client', '/boot.ts'],
            ],
            'absolute dot segment' => [
                'boot/client', '/demo/./boot.ts',
                ['{mount}/boot/client', '/demo/./boot.ts'],
                ['{mount}/boot/client', '/demo/boot.ts'],
            ],
            'null entrypoint' => [
                'boot/client', null,
                ['{mount}/boot/client'],
                ['{mount}/boot/client'],
            ],
        ];
        foreach (['', '/demo'] as $prefix) {
            foreach ($cases as $name => [$client, $entrypoint, $emitted, $requests]) {
                $resolve = static fn (string $value): string => str_replace('{mount}', $prefix, $value);
                yield $name . ' ' . ($prefix ?: '/')
                    => [$prefix, $client, $entrypoint, array_map($resolve, $emitted), array_map($resolve, $requests)];
            }
        }
    }

    #[DataProvider('generatedViteResourceUrls')]
    public function test_custom_vite_diagnostics_match_the_script_urls_actually_generated(
        string $prefix,
        string $client,
        ?string $entrypoint,
        array $emitted,
        array $requests,
    ): void {
        $html = new ViteAutoHtml(
            development: true,
            devViteClient: $client,
            devEntrypoint: $entrypoint,
        );
        $middleware = $this->middleware($prefix, development: true, html: $html);
        self::assertSame($emitted, $html->javascript);

        // Die öffentliche HTML-Ausgabe bleibt die Referenz für angeforderte Ressourcen.
        $document = new \DOMDocument();
        self::assertTrue($document->loadHTML($html->render()));
        $actualSources = [];
        foreach ($document->getElementsByTagName('script') as $script) {
            $actualSources[] = $script->getAttribute('src');
        }
        self::assertSame($emitted, $actualSources);
        foreach ($requests as $path) {
            foreach (['GET', 'HEAD'] as $method) {
                $response = $this->process($middleware, new ServerRequest(uri: $path, method: $method));
                $this->assertViteDiagnostic($response, $method === 'HEAD');
            }
        }
    }

    public function test_custom_config_normalization_never_permits_raw_request_traversal(): void
    {
        foreach (['', '/demo'] as $prefix) {
            $html = new ViteAutoHtml(development: true, devViteClient: '', devEntrypoint: './src/main.ts');
            $middleware = $this->middleware($prefix, development: true, html: $html);
            foreach (['/./src/main.ts', '/../src/main.ts', '/%2e/src/main.ts', '/%2e%2e/src/main.ts'] as $path) {
                $response = $this->process($middleware, $this->rawRequest($prefix . $path));
                $this->assertFailure($response);
                self::assertStringNotContainsString('Vite development resource', (string) $response->getBody());
            }
        }
    }

    public function test_custom_vite_matching_preserves_absolute_and_relative_url_boundaries(): void
    {
        $relative = new ViteAutoHtml(
            development: true,
            devViteClient: 'boot/client',
            devEntrypoint: 'boot/entry',
            startHtml: '<div>' . self::SHELL . '</div>',
        );
        $middleware = $this->middleware('/demo', development: true, html: $relative);
        self::assertSame(['/demo/boot/client', '/demo/boot/entry'], $relative->javascript);
        foreach (['/boot/client', '/boot/entry'] as $path) {
            $this->assertFailure($this->process($middleware, new ServerRequest(uri: $path)));
        }
        foreach (['/demo/boot/client-extra', '/demo/boot/client/extra', '/demo/boot/entry-extra'] as $path) {
            $response = $this->process($middleware, new ServerRequest(uri: $path));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString(self::SHELL, (string) $response->getBody());
        }

        // Root-absolute Konfiguration erzeugt keinen zusätzlichen Mount-Alias.
        $absolute = new ViteAutoHtml(
            development: true,
            devViteClient: '/absolute-client',
            devEntrypoint: '/absolute-entry',
            startHtml: '<div>' . self::SHELL . '</div>',
        );
        $middleware = $this->middleware('/demo', development: true, html: $absolute);
        self::assertSame(['/absolute-client', '/absolute-entry'], $absolute->javascript);
        foreach (['/demo/absolute-client', '/demo/absolute-entry'] as $path) {
            $response = $this->process($middleware, new ServerRequest(uri: $path));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString(self::SHELL, (string) $response->getBody());
        }
    }

    public function test_encoded_configuration_separators_do_not_claim_an_unrelated_normalized_path(): void
    {
        $html = new ViteAutoHtml(
            development: true,
            devViteClient: '',
            devEntrypoint: '/demo/a%2F..%2Fb.js',
        );
        $middleware = $this->middleware('/demo', development: true, html: $html);
        self::assertContains('/demo/a%2F..%2Fb.js', $html->javascript);
        foreach (['/demo/b.js', '/demo/a%2F..%2Fb.js'] as $path) {
            $response = $this->process($middleware, new ServerRequest(uri: $path));
            $this->assertFailure($response);
            self::assertStringNotContainsString('Vite development resource', (string) $response->getBody());
        }
    }

    public function test_scheme_like_relative_configuration_does_not_claim_a_different_local_route(): void
    {
        foreach (['', '/demo'] as $prefix) {
            $html = new ViteAutoHtml(
                development: true,
                devViteClient: '',
                devEntrypoint: 'data:text/javascript,hello',
                startHtml: '<div>' . self::SHELL . '</div>',
            );
            $middleware = $this->middleware($prefix, development: true, html: $html);
            self::assertContains($prefix . '/data:text/javascript,hello', $html->javascript);
            $response = $this->process($middleware, new ServerRequest(uri: $prefix . '/text/javascript,hello'));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString(self::SHELL, (string) $response->getBody());

            $response = $this->process($middleware, new ServerRequest(uri: $prefix . '/data:text/javascript,hello'));
            $this->assertFailure($response);
            self::assertStringNotContainsString('Vite development resource', (string) $response->getBody());
        }
    }

    public function test_controls_in_configuration_do_not_create_underscore_alias_routes(): void
    {
        foreach (['', '/demo'] as $prefix) {
            $configured = $prefix . "/boot\0entry";
            $html = new ViteAutoHtml(
                development: true,
                devViteClient: '',
                devEntrypoint: $configured,
                startHtml: '<div>' . self::SHELL . '</div>',
            );
            $middleware = $this->middleware($prefix, development: true, html: $html);
            self::assertContains($configured, $html->javascript);
            $response = $this->process($middleware, new ServerRequest(uri: $prefix . '/boot_entry'));
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString(self::SHELL, (string) $response->getBody());
            $this->assertFailure($this->process($middleware, $this->rawRequest($configured)));
        }
    }

    public function test_external_custom_urls_never_claim_matching_local_routes(): void
    {
        foreach (['', '/demo'] as $prefix) {
            foreach (['https://cdn.invalid', '//cdn.invalid', 'custom+vite://cdn.invalid'] as $origin) {
                $html = new ViteAutoHtml(
                    development: true,
                    devViteClient: $origin . '/remote/client',
                    devEntrypoint: $origin . '/remote/entry',
                    startHtml: '<div>' . self::SHELL . '</div>',
                );
                $middleware = $this->middleware($prefix, development: true, html: $html);
                self::assertSame([$origin . '/remote/client', $origin . '/remote/entry'], $html->javascript);
                foreach (['remote/client', 'remote/entry'] as $path) {
                    $response = $this->process($middleware, new ServerRequest(uri: $prefix . '/' . $path));
                    self::assertSame(200, $response->getStatusCode());
                    self::assertStringContainsString(self::SHELL, (string) $response->getBody());
                }
            }
        }
    }

    public function test_empty_custom_resource_urls_do_not_claim_navigation_or_src_routes(): void
    {
        foreach (['', '/demo'] as $prefix) {
            foreach ([null, ''] as $entrypoint) {
                $html = new ViteAutoHtml(
                    development: true,
                    devViteClient: '',
                    devEntrypoint: $entrypoint,
                    startHtml: '<div>' . self::SHELL . '</div>',
                );
                $middleware = $this->middleware($prefix, development: true, html: $html);
                self::assertSame($entrypoint === null ? [''] : ['', ''], $html->javascript);
                $response = $this->process($middleware, new ServerRequest(uri: $prefix . '/'));
                self::assertSame(200, $response->getStatusCode());
                self::assertStringContainsString(self::SHELL, (string) $response->getBody());
                $response = $this->process($middleware, new ServerRequest(uri: $prefix . '/src/main.ts'));
                $this->assertFailure($response);
                self::assertStringNotContainsString('Vite development resource', (string) $response->getBody());
            }
        }
    }

    public function test_vite_near_matches_keep_the_existing_navigation_and_asset_policy(): void
    {
        $cases = [
            ['@vite/client-extra', 200],
            ['@vite/client/extra', 200],
            ['@vite/env-extra', 200],
            ['@id-other/package', 200],
            ['@fs-other/package', 200],
            ['src/other', 200],
            ['src/other.ts', 404],
        ];
        foreach (['', '/demo'] as $prefix) {
            foreach ([false, true] as $development) {
                foreach ($cases as [$path, $status]) {
                    $response = $this->request($prefix, $path, development: $development);
                    self::assertSame($status, $response->getStatusCode());
                    if ($status === 200) {
                        self::assertStringContainsString(self::SHELL, (string) $response->getBody());
                    } else {
                        $this->assertFailure($response);
                    }
                    self::assertStringNotContainsString('Vite development resource', (string) $response->getBody());
                }
            }
        }
        $response = $this->process(
            $this->middleware('/demo', development: true),
            new ServerRequest(uri: '/demo-other/@vite/client'),
        );
        $this->assertFailure($response);
    }

    public function test_production_never_serves_reserved_vite_files_but_keeps_normal_sources_public(): void
    {
        foreach (['@vite/client', '@vite/env', '@id/module', '@fs/workspace/main.ts'] as $path) {
            $this->writeBundleFile('assets/' . $path, self::SECRET);
        }
        $this->writeBundleFile('assets/src/main.ts', self::PUBLIC_JS);
        $this->writeBundleFile('assets/boot/client', self::PUBLIC_JS);

        foreach (['', '/demo'] as $prefix) {
            $html = new ViteAutoHtml(
                development: false,
                devViteClient: 'boot/client',
                devEntrypoint: 'src/main.ts',
            );
            $middleware = $this->middleware($prefix, html: $html);
            foreach (['@vite/client', '@vite/env', '@id/module', '@fs/workspace/main.ts'] as $path) {
                $response = $this->process($middleware, new ServerRequest(uri: $prefix . '/' . $path));
                $this->assertFailure($response);
                self::assertStringNotContainsString('Vite development resource', (string) $response->getBody());
            }
            foreach (['src/main.ts', 'boot/client'] as $path) {
                $response = $this->process($middleware, new ServerRequest(uri: $prefix . '/assets/' . $path));
                self::assertSame(200, $response->getStatusCode());
                self::assertSame(self::PUBLIC_JS, (string) $response->getBody());
            }
        }
    }

    public function test_vite_diagnosis_precedes_file_access_html_rendering_and_negotiation(): void
    {
        foreach (['', '/demo'] as $prefix) {
            $html = new ViteAutoHtml(development: true);
            $middleware = $this->middleware(
                $prefix,
                development: true,
                html: $html,
                bundleDir: $this->root . '/missing-development-bundle',
            );
            $probe = new class {
                public bool $rendered = false;

                public function __toString(): string
                {
                    $this->rendered = true;
                    throw new \RuntimeException('Vite requests must not render the shell.');
                }
            };
            $html->meta['render-sentinel'] = $probe;
            foreach (['GET', 'HEAD'] as $method) {
                $request = $this->headerRequest('/@vite/client?token=' . self::SECRET, [
                    'Accept' => "text/html\0",
                    'Sec-Fetch-Dest' => 'unknown',
                ], $method);
                $this->assertViteDiagnostic($this->process($middleware, $request), $method === 'HEAD');
                self::assertFalse($probe->rendered);
            }
        }

        // Auch die Produktionssperre greift vor einem mittlerweile verlorenen Bundle.
        $production = $this->middleware('/demo');
        self::assertTrue(rename($this->root . '/dist', $this->root . '/offline-vite-dist'));
        clearstatcache(true);
        $this->assertFailure($this->process($production, new ServerRequest(uri: '/@vite/client')));
    }

    public function test_excluded_api_requests_take_priority_over_vite_resource_detection(): void
    {
        foreach (['', '/demo'] as $prefix) {
            $html = new ViteAutoHtml(development: true, devEntrypoint: 'api/entry.ts');
            $middleware = $this->middleware($prefix, development: true, html: $html);
            $request = new ServerRequest(uri: $prefix . '/api/entry.ts', method: 'POST');
            $handler = new class implements RequestHandlerInterface {
                public ?ServerRequestInterface $received = null;

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->received = $request;
                    return new EmptyResponse(418);
                }
            };
            $response = $middleware->process($request, $handler);
            self::assertSame($request, $handler->received);
            self::assertSame(418, $response->getStatusCode());

            // Ein explizit ausgeschlossener API-Mount hat auch vor festen Internals Vorrang.
            $api = $this->middleware('/api', development: true);
            $request = new ServerRequest(uri: '/api/@vite/client', method: 'POST');
            $response = $api->process($request, $handler);
            self::assertSame($request, $handler->received);
            self::assertSame(418, $response->getStatusCode());
        }
    }

    public function test_vite_detection_uses_the_effective_mount_without_weakening_private_paths(): void
    {
        $middleware = $this->middleware('/demo', development: true, mount: '/vendor');
        foreach (['/@vite/client', '/vendor/@vite/client'] as $path) {
            $this->assertViteDiagnostic($this->process($middleware, new ServerRequest(uri: $path)));
        }
        foreach (['/demo/@vite/client', '/vendor/@fs/project/node_modules/package.js', '/@fs/project/vendor/package.js'] as $path) {
            $response = $this->process($middleware, new ServerRequest(uri: $path));
            $this->assertFailure($response);
            self::assertStringNotContainsString('Vite development resource', (string) $response->getBody());
        }
    }

    private function assertViteDiagnostic(ResponseInterface $response, bool $head = false): void
    {
        $this->assertFailure($response, 500, $head);
        if (!$head) {
            self::assertSame(
                'Vite development resource reached SPA-Serve. Check server.proxy in vite.config and open the Vite development URL.',
                (string) $response->getBody(),
            );
        }
    }

    private function headerRequest(string $path, array $headers, string $method = 'GET'): ServerRequestInterface
    {
        // Fehlerhafte Adapter-Header unverändert prüfen, auch wenn PSR-7 sie ablehnen würde.
        $normalized = array_change_key_case($headers, CASE_LOWER);
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willReturn((new ServerRequest(uri: $path))->getUri());
        $request->method('getMethod')->willReturn($method);
        $request->method('getHeaders')->willReturn($headers);
        $request->method('hasHeader')->willReturnCallback(
            static fn (string $name): bool => array_key_exists(strtolower($name), $normalized),
        );
        $request->method('getHeader')->willReturnCallback(
            static fn (string $name): array => (array) ($normalized[strtolower($name)] ?? []),
        );
        $request->method('getHeaderLine')->willReturnCallback(
            static fn (string $name): string => implode(', ', (array) ($normalized[strtolower($name)] ?? [])),
        );
        return $request;
    }

    private function assertFallbackVary(ResponseInterface $response): void
    {
        $vary = array_map('trim', explode(',', strtolower($response->getHeaderLine('Vary'))));
        self::assertContains('accept', $vary);
        self::assertContains('sec-fetch-dest', $vary);
    }

    private function request(
        string $prefix,
        string $path,
        string $method = 'GET',
        bool $development = false,
        array $headers = [],
    ): ResponseInterface {
        return $this->process(
            $this->middleware($prefix, development: $development),
            new ServerRequest(uri: $prefix . '/' . $path, method: $method, headers: $headers),
        );
    }

    private function middleware(
        string $prefix = '',
        bool $development = false,
        ?HtmlGenerator $html = null,
        ?string $mount = null,
        ?string $bundleDir = null,
    ): SpaStaticFileServerMw {
        $environment = $development ? EnvironmentType::DEVELOPMENT : EnvironmentType::PRODUCTION;
        $app = new BraceApp($environment, url_prefix: $prefix);
        $app->addModule(new BraceRequestLaminasModule());
        $middleware = new SpaStaticFileServerMw(
            rootDir: $this->root . '/dist',
            html: $html ?? new ViteAutoHtml(development: $development, startHtml: '<main>' . self::SHELL . '</main>'),
            bundleDir: $bundleDir === null ? 'dist' : basename($bundleDir),
            mount: $mount,
        );
        $middleware->_setApp($app);
        return $middleware;
    }

    private function rawRequest(string $path, string $method = 'GET'): ServerRequestInterface
    {
        // Der Test simuliert kaputte Adapterdaten ohne URI-Normalisierung.
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);
        return (new ServerRequest(uri: '/', method: $method))->withUri($uri, true);
    }

    private function process(SpaStaticFileServerMw $middleware, ServerRequestInterface $request): ResponseInterface
    {
        $fallback = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('SPA-owned request unexpectedly reached the downstream handler.');
            }
        };
        return $middleware->process($request, $fallback);
    }

    private function assertFailure(ResponseInterface $response, int $status = 404, bool $head = false): void
    {
        $body = (string) $response->getBody();
        $headers = json_encode($response->getHeaders(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString(self::SECRET, $body);
        self::assertStringNotContainsString($this->root, $body);
        self::assertStringNotContainsString(self::SECRET, $headers);
        self::assertStringNotContainsString($this->root, $headers);
        self::assertFalse($response->hasHeader('X-Injected'));
        self::assertFalse($response->hasHeader('Location'));
        self::assertStringNotContainsString(self::SHELL, $body);
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('Stack trace', $body);
        if ($head) {
            self::assertSame('', $body);
        } else {
            self::assertNotSame('', trim($body), 'Failures need a useful public error message.');
        }
    }

    private function writeBundleFile(string $path, string $content): string
    {
        $file = $this->root . '/dist/' . $path;
        if (!is_dir(dirname($file))) {
            self::assertTrue(mkdir(dirname($file), 0700, true));
        }
        self::assertSame(strlen($content), file_put_contents($file, $content));
        return $file;
    }

    private function removeTree(string $path): void
    {
        // Niemals Fixture-Symlinks verfolgen; auch Fehlerfälle sicher aufräumen.
        if (is_link($path)) {
            unlink($path);
        } elseif (is_file($path)) {
            chmod($path, 0600);
            unlink($path);
        } elseif (is_dir($path)) {
            chmod($path, 0700);
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);
        }
    }
}
