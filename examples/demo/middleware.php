<?php

use Brace\Core\EnvironmentType;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;

$development = $app->environmentType === EnvironmentType::DEVELOPMENT;

$html = $development
    ? ViteAutoHtml::development(
        entrypoint: '/src/main.ts',
        meta: ['spa-api-base-url' => '/api'],
        startElement: 'demo-app',
    )
    : ViteAutoHtml::production(
        meta: ['spa-api-base-url' => '/api'],
        css: ['/assets/app.css'],
        javascript: ['/assets/app.js'],
        startElement: 'demo-app',
    );

$app->addMiddleware(new SpaStaticFileServerMw(
    bundleDir: __DIR__ . '/dist',
    html: $html,
));
