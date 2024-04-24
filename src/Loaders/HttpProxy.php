<?php

namespace Brace\SpaServe\Loaders;

use Brace\Core\BraceResponseFactoryInterface;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class HttpProxy
{


    public function __construct(
        private string $proxyUrl,
        private BraceResponseFactoryInterface $responseFactory,
        private string $stripPrefix = "",
    ) {
    }


    public function proxyRequest(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if (startsWith($path, $this->stripPrefix))
            $path = substr($path, strlen($this->stripPrefix));

        $method = $request->getMethod();
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = $name . ": " . implode(", ", $values);
        }
        $bodyContent = (string)$request->getBody();


        // extract port from url



        $ch = curl_init($this->proxyUrl . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyContent);
        curl_setopt($ch, CURLOPT_ENCODING , 'gzip');
        //curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);

        $response = new Response();
        //$stream = fopen("php://output", "w+");
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headers) {
            $len = strlen($header);
            $headerArr = explode(':', $header, 2);
            if (count($headerArr) < 2) { // ignore invalid headers
                return $len;
            }
            if (in_array(strtolower($headerArr[0]), ["content-type"])) {
                out ("header: " . $header);
                header(trim ($header), false);
            }



            $name = strtolower(trim($header[0]));
            if (!array_key_exists($name, $headers)) {
                $headers[$name] = [trim($header[1])];
            } else {
                $headers[$name][] = trim($header[1]);
            }


            return $len;
        });


        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($response) {
            echo $data;
            return strlen($data);
        });

        $data = curl_exec($ch);
        curl_close($ch);
        //echo $data;

       // fclose($stream);
        out("finish");
        if (curl_errno($ch)) {
           echo  "SpaServe ProxyLoader: Error:" . curl_error($ch);
           exit(1);
        }

        exit(0);

    }


}
