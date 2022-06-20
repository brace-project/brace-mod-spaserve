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
        "jpg" => "image/jpg",
        "svg" => "image/svg+xml",
        "woff2" => "font/woff2"
    ];


    public function __construct(
        public PhoreDirectory|string $rootDir,
        public string $mount = "/static",
        public string $defaultFile = "main.html",
        public bool $liveReload = false
    ) {
        $this->rootDir = phore_dir($this->rootDir)->assertDirectory();
    }


    protected function getContentTypeFor($file) {
        $file = phore_file($file);
        return self::MIME_MAP[$file->getExtension()] ?? throw new \InvalidArgumentException("Mimetype undefined: $file");
    }


    protected function inotifyWait() {
        exec("inotifywait -r -e create --format '%w%f' " . escapeshellarg($this->rootDir), $out, $result);
        if ($result !== 0) {
            throw new \Exception("inotify error: " . implode(" ", $out) . " - make sure you have inotify-tools installed");
        }
    }


    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ( ! startsWith($path, $this->mount))
            return $handler->handle($request);

        if (isset ($request->getQueryParams()["__brace_inotify_wait"]) && $this->liveReload) {
            $this->inotifyWait();
            return $this->app->responseFactory->createResponse();
        }

        $file = substr($path, strlen($this->mount));


        $data = "";
        if (str_contains($file, ".")) {
            if (phore_file($file)->getFilename() === "@") {
                $dir = phore_dir($this->rootDir->withSubPath(phore_file($file)->getDirname()));
                foreach ($dir->getListSorted("*." . phore_file($file)->getExtension(), true) as $inludeFile) {
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
            $html = $this->rootDir->withRelativePath($this->defaultFile)->assertFile()->get_contents();
            if ($this->liveReload) {
                $html .= file_get_contents(__DIR__ . "/../js/livereload.html");
            }
            return $this->app->responseFactory->createResponseWithBody(
                $html,
                200, ["Content-Type" => "text/html"]
            );
        }
    }
}
