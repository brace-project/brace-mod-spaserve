<?php

namespace Brace\SpaServe\Loaders;

use Brace\Core\BraceApp;
use Brace\SpaServe\SpaStaticFileServerMw;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ProxyLoader implements SpaServeLoader
{

    public function __construct(
        private string $proxyUrl,
        private string $proxyPath,
        private string $stripPrefix = ""
    ) {
    }


    private BraceApp $app;


    public function setApp(BraceApp $app): void
    {
        $this->app = $app;
    }

    public function matchesRoute(string $path): bool
    {
        out("Checking path: $path");
        return fnmatch($this->proxyPath, $path);
    }

    public function getResponse(string $path, SpaStaticFileServerMw $middleware, ServerRequestInterface $request): ResponseInterface
    {
        $proxy = new HttpProxy($this->proxyUrl,$this->app->responseFactory, $this->stripPrefix);
        return $proxy->proxyRequest($request);
    }
}
