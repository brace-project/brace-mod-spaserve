<?php

namespace Brace\SpaServe\Loaders;

interface SpaServeLoader
{
    public function matchesRoute(string $route) : bool;

    public function getResponse(string $route) : ServerResponse;
}