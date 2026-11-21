<?php

declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/autoload.php';

use Brace\SpaServe\Codegen\TypeScriptApiStubGenerator;
use Phore\Schema\Schema\Type\PrimitiveSchemaType;

$output = (new TypeScriptApiStubGenerator())->generate([
    ['name' => 'Query.Test', 'path' => '/query', 'methods' => ['GET'],
        'response' => new PrimitiveSchemaType('string'),
        'queryParams' => ['search' => new PrimitiveSchemaType('string'), 'limit' => new PrimitiveSchemaType('int')],
        'queryOptional' => ['limit'], 'queryOpen' => true],
], []);
foreach (['[key: string]: unknown', 'search: string', 'limit?: number'] as $expected) {
    if (!str_contains($output, $expected)) {
        throw new RuntimeException("Missing generated declaration: $expected");
    }
}
$fixture = new class {
    #[\Brace\Router\Attributes\BraceQuery('methodOnly', type: 'bool', default: false)]
    public function action(#[\Brace\Router\Attributes\BraceQuery(name: 'wire')] int $limit = 10, array $query = []): string { return ''; }
};
$app = new \Brace\Core\BraceApp();
$app->addModule(new \Brace\Router\RouterModule());
$app->router->on('GET@/query', [$fixture, 'action'], name: 'Query.Integration');
$target = sys_get_temp_dir() . '/brace-query-' . bin2hex(random_bytes(8)) . '.ts';
try {
    $module = new \Brace\SpaServe\Codegen\TypeScriptApiGeneratorModule($target);
    $app->addModule($module);
    $module->load();
    $generated = file_get_contents($target);
    foreach (['wire?: number', 'methodOnly?: boolean', '[key: string]: unknown'] as $expected) {
        if (!str_contains($generated, $expected)) {
            throw new RuntimeException("Missing integrated query declaration: $expected");
        }
    }
} finally {
    if (is_file($target)) { unlink($target); }
}
echo "QueryParameterCodegen: OK\n";
