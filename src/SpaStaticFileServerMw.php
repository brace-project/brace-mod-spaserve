<?php

namespace Brace\SpaServe;

use Brace\Core\Base\BraceAbstractMiddleware;
use Phore\FileSystem\PhoreDirectory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SpaStaticFileServerMw extends BraceAbstractMiddleware
{

    const MIME_MAP = [
        "html" => "text/html",
        "js" => "text/javascript",
        "css" => "text/css",
        "png" => "image/png",
        "svg" => "image/svg+xml",
        "woff2" => "font/woff2"
    ];


    public function __construct(
        public PhoreDirectory $rootDir,
        public string $mount = "/static",
        public string $defaultFile = "main.html"
    ) {

    }


    protected function getContentTypeFor($file) {
        $file = phore_file($file);
        return self::MIME_MAP[$file->getExtension()] ?? throw new \InvalidArgumentException("Mimetype undefined: $file");
    }



    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ( ! startsWith($path, $this->mount))
            return $handler->handle($request);

        $file = substr($path, strlen($this->mount));

        $data = "";
        if (str_contains($file, ".")) {
            if (phore_file($file)->getFilename() === "@") {
                $dir = phore_dir($this->rootDir->withSubPath(phore_file($file)->getDirname()));
                foreach ($dir->getListSorted("*." . phore_file($file)->getExtension()) as $inludeFile) {
                    $data .= $inludeFile->assertFile()->get_contents();
                    $data .= "\n/* Inluded from file: $inludeFile */\n\n";
                }
                return $this->app->responseFactory->createResponseWithBody(
                    $data,
                    200, ["Content-Type" => $this->getContentTypeFor($file)]
                );
            } else {
                return $this->app->responseFactory->createResponseWithBody(
                    $this->rootDir->withRelativePath($file)->assertFile()->get_contents(),
                    200, ["Content-Type" => $this->getContentTypeFor($file)]
                );
            }
        } else {
            return $this->app->responseFactory->createResponseWithBody(
                $this->rootDir->withRelativePath($this->defaultFile)->assertFile()->get_contents(),
                200, ["Content-Type" => "text/html"]
            );
        }
    }
}
