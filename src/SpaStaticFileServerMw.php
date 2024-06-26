<?php

namespace Brace\SpaServe;

use Brace\Core\Base\BraceAbstractMiddleware;
use Brace\SpaServe\Helper\FileContentRewriter;
use Brace\SpaServe\Loaders\HttpProxy;
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





        public string $indexFile = "index.html",

        /**
         * Redirect all files that cannot be found to the default file (for use in SPA scenarios)
         *
         * @var bool
         */
        public bool $historyApiFallback = true,

        /**
         *
         * @var bool
         */
        public bool $developmentMode = false,

        /**
         * Exclude paths from static file server. Use fnmatch syntax.
         * This should be used to exclude api paths from static file server.
         *
         * @var array|string[]
         */
        public array $exclude = ["/api/*"],

        public string $xFrameOptions = "SAMEORIGIN",

    ) {
        $this->rootDir = phore_dir($this->rootDir)->assertDirectory();

        // Must start with /
        if ( ! str_starts_with($this->mount, "/"))
            $this->mount = "/".$this->mount;

        // Mutst not end with /
        if (str_ends_with($this->mount, "/"))
            $this->mount = substr($this->mount, 0, -1);
    }


    protected function getContentTypeFor($file) {
        $file = phore_file($file);
        return self::MIME_MAP[$file->getExtension()] ?? throw new \InvalidArgumentException("Mimetype undefined: $file");
    }





    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {


        $path = $request->getUri()->getPath();

        // Only process GET requests
        if ($request->getMethod() !== "GET")
            return $handler->handle($request);
        
        if ( ! startsWith($path, $this->mount))
            return $handler->handle($request);

        // Check if path is excluded
        foreach ($this->exclude as $exclude) {
            if (fnmatch($exclude, $path))
                return $handler->handle($request);
        }

        $fileContentRewriter = new FileContentRewriter([
            "%%ROUTE_PREFIX%%" => $this->app->router->getRoutePrefix()
        ]);


        $file = substr($path, strlen($this->mount));

        $rootDir = phore_dir($this->rootDir);


        $curFile = $rootDir->withRelativePath($file)->asFile();
        if ($this->developmentMode) {
            $proxy = new HttpProxy("http://localhost:4000", $this->app->responseFactory, $this->mount, $fileContentRewriter);
            $proxy->proxyRequest($request); // Will quit here if proxy was successful
        }
        if ($curFile->exists()) {
            if ($curFile->isDirectory())
                $curFile = $curFile->withFileName($this->indexFile);
            return $this->app->responseFactory->createResponseWithBody(
                $fileContentRewriter->rewrite($curFile->assertFile()->get_contents()),
                200, ["Content-Type" => $this->getContentTypeFor($curFile), "X-Frame-Options" => $this->xFrameOptions]
            );
        }
        
        if ($this->historyApiFallback) {
            $defaultFile = $rootDir->withRelativePath($this->indexFile);
            if ( ! $defaultFile->exists())
                throw new \InvalidArgumentException("Default file not found: $defaultFile");
            return $this->app->responseFactory->createResponseWithBody(
                $fileContentRewriter->rewrite($defaultFile->assertFile()->get_contents()),
                200, ["Content-Type" => $this->getContentTypeFor($defaultFile), "X-Frame-Options" => $this->xFrameOptions]
            );
        }

        return $this->app->responseFactory->createResponse(404, "File not found");
    }
}
