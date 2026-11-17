<?php

use Brace\Core\EnvironmentType;
use Brace\SpaServe\Codegen\TypeScriptApiGeneratorModule;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;

$callback = static function (int $userId, string $locale): string {
    return "User {$userId} ({$locale})";
};
$app->router->on('GET@/api/users/:userId', $callback, [], 'User.Get');

$app->addModule(new TypeScriptApiGeneratorModule(
    targetFile: __DIR__ . '/src/generated-api.ts',
    autoGenerateInDevelopment: true,
));

// Insert this after the Brace API routes; Vite proxies /api to Brace.
$html = new ViteAutoHtml(
    development: $app->environmentType === EnvironmentType::DEVELOPMENT,
    css: ['/assets/app.css'],
    javascript: ['/assets/app.js'],
    devEntrypoint: '/src/main.ts',
    meta: ['spa-api-base-url' => '/api'],
    startHtml: '<demo-app><main id="content"></main></demo-app>',
);

$app->addMiddleware(new SpaStaticFileServerMw(
    bundleDir: __DIR__ . '/dist',
    html: $html,
    excludePaths: ['/api'],
));
