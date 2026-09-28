<?php

declare(strict_types=1);

use Brace\SpaServe\Codegen\TypeScriptApiStubModule;

$callback = static function (int $userId, string $locale): string {
    return "User {$userId} ({$locale})";
};

$api = new TypeScriptApiStubModule(
    targetFile: __DIR__ . '/src/generated-api.ts',
    autoGenerateInDevelopment: true,
);
$api->route(
    name: 'User.Get',
    path: '/api/users/{userId}',
    methods: 'GET',
    callback: $callback,
);

return [
    'api' => $api,
    'callback' => $callback,
];
