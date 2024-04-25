<?php

namespace Brace\SpaServe\Loaders;


use Brace\Core\BraceApp;
use Brace\SpaServe\SpaStaticFileServerMw;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class StaticLoader implements SpaServeLoader
{

    public function __construct(
        private string $rootUrl,
        private string $proxyPath,
        private string $stripPrefix = "",
        private bool $active = true,
        private bool $historyApiFallback = true

    ) {
    }


    private BraceApp $app;


    public function setApp(BraceApp $app): void
    {
        $this->app = $app;
    }

    public function matchesRoute(string $path): bool
    {
        if ( ! $this->active)
            return false;
        return fnmatch($this->proxyPath, $path);
    }

    public function getResponse(string $path, SpaStaticFileServerMw $middleware, ServerRequestInterface $request): ResponseInterface
    {
        $path = phore_dir($this->rootUrl);
        $file = $path->withRelativePath($path);
        if ($file->exists())
            return $this->app->responseFactory->createResponseWithBody(
                $file->get_contents(),
                200, ["Content-Type" => $this->getContentTypeFor($file)]
            );
    }
}
