<?php

namespace Brace\SpaServe\Loaders;

use Brace\SpaServe\SpaStaticFileServerMw;

class EsbuildLoader implements SpaServeLoader
{

    public function __construct(string $mount, $script) {

    }


    public function matchesRoute(string $route): bool
    {
        // TODO: Implement matchesRoute() method.
    }

    public function getResponse(string $route, SpaStaticFileServerMw $middleware): ServerResponse
    {


    }
}