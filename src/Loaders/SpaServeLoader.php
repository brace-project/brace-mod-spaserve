<?php

namespace Brace\SpaServe\Loaders;

use Brace\SpaServe\SpaStaticFileServerMw;
use Psr\Http\Message\ResponseInterface;

interface SpaServeLoader
{
    public function matchesRoute(string $path) : bool;

    public function getResponse(string $path, SpaStaticFileServerMw $middleware) : ResponseInterface;
}
