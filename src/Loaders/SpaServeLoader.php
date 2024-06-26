<?php

namespace Brace\SpaServe\Loaders;

use Brace\Core\BraceApp;
use Brace\SpaServe\SpaStaticFileServerMw;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface SpaServeLoader
{
    public function setApp(BraceApp $app) : void;

    public function matchesRoute(string $path) : bool;

    public function getResponse(string $path, SpaStaticFileServerMw $middleware, ServerRequestInterface $request) : ResponseInterface;
}
