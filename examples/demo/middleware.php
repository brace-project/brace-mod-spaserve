<?php

use Brace\Command\CommandModule;
use Brace\Core\EnvironmentType;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;

// CommandModule is added once during application bootstrap.
$app->addModule(new CommandModule());

$contract = require __DIR__ . '/api-stub.php';
$app->router->on('GET@/api/users/:userId', $contract['callback']);

// TypeScriptApiStubModule auto-generates only in DEVELOPMENT.
// Set autoGenerateInDevelopment: false in api-stub.php to disable this path.
$app->addModule($contract['api']);

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
