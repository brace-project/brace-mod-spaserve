<?php

namespace Brace\SpaServe;

use Brace\Core\Base\BraceAbstractMiddleware;
use Brace\SpaServe\Loaders\SpaServeLoader;
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
        "woff2" => "font/woff2",
        "woff" => "font/woff",
        "webp" => "image/webp",
        "json" => "application/json",
        "txt" => "text/plain",
        "ico" => "image/x-icon",
        "map" => "application/json",
        "ttf" => "font/ttf",
        "eot" => "application/vnd.ms-fontobject",
        "otf" => "font/otf",
        "xml" => "application/xml",
        "pdf" => "application/pdf",
        "zip" => "application/zip",
        "gz" => "application/gzip",
        "tar" => "application/x-tar",
        "rar" => "application/x-rar-compressed",
        "7z" => "application/x-7z-compressed",
        "mp4" => "video/mp4",
        "webm" => "video/webm",
        "ogg" => "video/ogg",
        "mp3" => "audio/mpeg",
        "wav" => "audio/wav",
        "flac" => "audio/flac",
        "aac" => "audio/aac",
        "m4a" => "audio/m4a",
        "csv" => "text/csv",
        "ts" => "text/typescript",
        "md" => "text/markdown",
        "sh" => "text/x-shellscript",


    ];


    public function __construct(
        /**
         * The directory to observe with inotify for changes (autoreload)
         * @var PhoreDirectory|string
         */
        public PhoreDirectory|string $rootDir,

        /**
         * Where to mount the static file server
         * @var string
         */
        public string $mount = "/static",
        public string $defaultFile = "main.html",
        public bool $liveReload = false,

        /**
         * List of Directories to observe for changes
         * @var array|null
         */
        public array|null $observeDirs = null,
        /**
         * @var SpaServeLoader[]
         */
        public $loaders = []
    ) {
        $this->rootDir = phore_dir($this->rootDir)->assertDirectory();

    }


    protected function getContentTypeFor($file) {
        $file = phore_file($file);
        return self::MIME_MAP[$file->getExtension()] ?? throw new \InvalidArgumentException("Mimetype undefined: $file");
    }


    protected function inotifyWait() {
        if ($this->observeDirs === null || count($this->observeDirs) === 0)
            throw new \InvalidArgumentException("Cannot use inotify without observeDir");

        $dirs = array_filter($this->observeDirs, fn($i) => "'" . escapeshellarg($i) . "'");

        exec("inotifywait -r -q -e create --format '%w%f' " . implode(" ", $dirs), $out, $result);
        if ($result !== 0) {
            throw new \Exception("inotify error: " . implode(" ", $out) . " - make sure you have inotify-tools installed");
        }
    }


    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        foreach ($this->loaders as $loader)
            $loader->setApp($this->app);

        $path = $request->getUri()->getPath();
        if ( ! startsWith($path, $this->mount))
            return $handler->handle($request);

        if (isset ($request->getQueryParams()["__brace_inotify_wait"]) && $this->liveReload) {
            $this->inotifyWait();
            return $this->app->responseFactory->createResponse();
        }



        $file = substr($path, strlen($this->mount));

        foreach ($this->loaders as $loader) {
            /* @var $loader SpaServeLoader */
            if ( $loader->matchesRoute($file)) {
                return $loader->getResponse($file, $this, $request);
            }
        }

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
