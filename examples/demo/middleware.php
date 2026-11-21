<?php

declare(strict_types=1);

use Brace\Body\BodyMiddleware;
use Brace\Core\Base\ExceptionHandlerMiddleware;
use Brace\Core\Base\JsonReturnFormatter;
use Brace\Core\Base\NotFoundMiddleware;
use Brace\Router\Attributes\BraceQuery;
use Brace\Router\RouterDispatchMiddleware;
use Brace\Router\RouterEvalMiddleware;
use Brace\SpaServe\Codegen\TypeScriptApiGeneratorModule;
use Brace\SpaServe\Html\ViteAutoHtml;
use Brace\SpaServe\SpaStaticFileServerMw;

// The application's bootstrap supplies $app and registers the router module.
$callback = static function (int $userId, #[BraceQuery] string $locale): string {
    return "User {$userId} ({$locale})";
};
$app->router->on('GET@/api/users/:userId', $callback, [], 'User.Get');

$app->addModule(new TypeScriptApiGeneratorModule(
    targetFile: __DIR__ . '/src/generated-api.ts',
));

$app->setPipe([
    new BodyMiddleware(),
    new ExceptionHandlerMiddleware(),
    new SpaStaticFileServerMw(
        rootDir: __DIR__,
        html: new ViteAutoHtml(
            apiBaseUrl: '/api',
            css: ['assets/dist/app.css'],
            javascript: ['assets/dist/app.js'],
            devEntrypoint: '/src/main.ts',
            startHtml: '<demo-app><main id="content"></main></demo-app>',
        ),
        mount: '/',
        excludePaths: ['/api'],
    ),
    new RouterEvalMiddleware(),
    // Insert application-specific API authentication here when required.
    new RouterDispatchMiddleware([new JsonReturnFormatter($app)]),
    new NotFoundMiddleware(),
]);
