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
        self::assertTrue(mkdir($this->root . '/dist/assets', 0700, true));
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
        $this->writeBundleFile($path, self::SECRET);
        foreach (['', '/demo'] as $prefix) {
            $this->assertFailure($this->request($prefix, $path));
            // Prozentkodierung darf die Dateiklassifikation nicht umgehen.
            $encoded = implode('/', array_map(
                static fn (string $segment): string => '%' . bin2hex($segment[0]) . substr($segment, 1),
                explode('/', $path),
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
        self::assertTrue(symlink($this->root . '/secret.txt', $this->root . '/dist/private'));

        foreach (['', '/demo'] as $prefix) {
            foreach (['assets/file.txt', 'assets/sibling/secret.txt', 'assets/chain.txt', 'private'] as $path) {
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
            $this->writeBundleFile($target, self::SECRET);
            $alias = 'assets/published-' . $index . '.js';
            self::assertTrue(symlink($this->root . '/dist/' . $target, $this->root . '/dist/' . $alias));
            foreach (['', '/demo'] as $prefix) {
                $this->assertFailure($this->request($prefix, $alias));
            }
        }
    }

    public function test_permits_internal_symlinks_to_public_files_and_directories(): void
    {
        self::assertTrue(symlink('app.js', $this->root . '/dist/assets/alias.js'));
        self::assertTrue(symlink('assets', $this->root . '/dist/public'));
        foreach (['', '/demo'] as $prefix) {
            foreach (['assets/alias.js', 'public/app.js'] as $path) {
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
        $this->writeBundleFile('documents/secret.txt', self::SECRET);
        foreach (['', '/demo'] as $prefix) {
            foreach (['documents', 'documents/', 'assets', 'assets/'] as $path) {
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
        $this->assertFailure($this->process($override, new ServerRequest(uri: '/demo/assets/app.js')));
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

    public function test_development_never_reads_existing_local_build_files(): void
    {
        $this->writeBundleFile('download', self::SECRET);
        foreach (['', '/demo'] as $prefix) {
            $this->assertFailure($this->request($prefix, 'assets/app.js', development: true));
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
        $this->expectExceptionMessage($this->root . '/missing-dist');
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

    private function request(
        string $prefix,
        string $path,
        string $method = 'GET',
        bool $development = false,
    ): ResponseInterface {
        return $this->process(
            $this->middleware($prefix, development: $development),
            new ServerRequest(uri: $prefix . '/' . $path, method: $method),
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
            $bundleDir ?? $this->root . '/dist',
            $html ?? new ViteAutoHtml(development: $development, startHtml: '<main>' . self::SHELL . '</main>'),
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
