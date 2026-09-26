<?php

use Brace\Core\EnvironmentType;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;

// Insert this after the Brace API routes; Vite proxies /api to Brace.
$html = new ViteAutoHtml(
    development: $app->environmentType === EnvironmentType::DEVELOPMENT,
    css: ['/assets/app.css'],
    javascript: ['/assets/app.js'],
    devEntrypoint: '/src/main.ts',
    meta: ['spa-api-base-url' => '/api'],
    startElement: 'demo-app',
);

$app->addMiddleware(new SpaStaticFileServerMw(
    bundleDir: __DIR__ . '/dist',
    html: $html,
    excludePaths: ['/api'],
));
