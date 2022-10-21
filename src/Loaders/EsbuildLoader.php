<?php

namespace Brace\SpaServe\Loaders;

use Brace\SpaServe\SpaStaticFileServerMw;
use Psr\Http\Message\ResponseInterface;

class EsbuildLoader implements SpaServeLoader
{

    private $mountPoint;

    public function __construct(
        private string $path,
        private string $script,
        private string $contentType,
        private string $cwd,
        private bool $minify = false
    ) {

    }


    public function matchesRoute(string $path): bool
    {
        return $path === $this->path;
    }

    public function getResponse(string $path, SpaStaticFileServerMw $middleware): ResponseInterface
    {
        $cwd = getcwd();
        chdir($this->cwd);

        $options = "";
        if ($this->minify)
            $options .= " --minify ";
        $proc = phore_proc("esbuild :input --bundle $options", ["input"=>$this->script]);
        $result = $proc->exec()->wait(false);

        $response = "";
        if ($result->failed()) {
            $response = "alert('SpoServe Esbuild Loader Error: " . addslashes($result->getSTDERRContents()) . "');";
        }
        if ($result->getSTDERRContents() !== "") {
            $response = "alert('SpoServe Esbuild Loader Warning: " . addslashes($result->getSTDERRContents()) . "');";
        }
        $response .= $result->getSTDOUTContents();
        chdir($cwd); // Reset CWD to original

        return $middleware->app->responseFactory->createResponseWithBody(
            $response,
            200,
            ["Content-Type" => $this->contentType]
        );
    }
}
