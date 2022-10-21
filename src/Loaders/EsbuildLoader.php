<?php

namespace Brace\SpaServe\Loaders;

class EsbuildLoader implements SpaServeLoader
{

    public function __construct(string $mount, $script) {

    }


    public function matchesRoute(string $route): bool
    {
        // TODO: Implement matchesRoute() method.
    }

    public function getResponse(string $route): ServerResponse
    {
        // TODO: Implement getResponse() method.
    }
}