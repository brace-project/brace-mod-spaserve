<?php

namespace Brace\SpaServe\Loaders;

use Brace\SpaServe\SpaStaticFileServerMw;

interface SpaServeLoader
{
    public function matchesRoute(string $route) : bool;

    public function getResponse(string $route, SpaStaticFileServerMw $middleware) : ServerResponse;
}