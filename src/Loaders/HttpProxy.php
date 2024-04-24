<?php

namespace Brace\SpaServe\Loaders;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class HttpProxy
{


    public function __construct(
        private string $proxyUrl,
        private string $proxyPath,
        private ResponseFactoryInterface $responseFactory
    ) {
    }


    public function proxyRequest(ServerRequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();
        $headers = $request->getHeaders();
        $bodyContent = $request->getBody();

        // Prepare headers for the proxy request
        $headerLines = [];
        foreach ($headers as $name => $values) {
            $headerLines[] = $name . ": " . implode(", ", $values);
        }
        $headerString = implode("\r\n", $headerLines);

        // Context for the request
        $opts = [
            'http' => [
                'method' => $method,
                'header' => $headerString,
                'content' => $bodyContent,
                'timeout' => 30
            ]
        ];

        $context = stream_context_create($opts);
        $result = @file_get_contents($this->proxyUrl . $path, false, $context);

        if ($result === FALSE) {
            $error = error_get_last();
            $response = $this->responseFactory->createResponse(500);
            return $response->withStatus(500)->withBody(new Stream($error));
        }

        $response = $this->responseFactory->createResponse(200);

        return $response->withStatus(200)->withBody(new Stream($result));
    }


}
