<?php

declare(strict_types=1);

namespace Brace\SpaServe\Codegen;

use Brace\Command\CommandModule;
use Brace\Core\BraceApp;
use Brace\Core\BraceModule;
use Phore\Schema\Parser\SchemaParser;
use Phore\Schema\Schema\ClassSchema;
use Phore\Schema\Schema\FunctionSchema;
use Phore\Schema\Schema\Type\ArraySchemaType;
use Phore\Schema\Schema\Type\ClassReferenceSchemaType;
use Phore\Schema\Schema\Type\IntersectionSchemaType;
use Phore\Schema\Schema\Type\SchemaType;
use Phore\Schema\Schema\Type\UnionSchemaType;

final class TypeScriptApiGeneratorModule implements BraceModule
{
    private ?BraceApp $app = null;

    public function __construct(
        private readonly string $targetFile,
        private readonly SchemaParser $schemaParser = new SchemaParser(),
        private readonly TypeScriptApiStubGenerator $generator = new TypeScriptApiStubGenerator(),
        private readonly GeneratedFileWriter $writer = new GeneratedFileWriter(),
        private readonly string $commandName = 'spa-api-build',
        private readonly string $apiMountPath = '',
    ) {
    }

    /** Register generation for all named routes already configured on the Brace router. */
    public function register(BraceApp $app): void
    {
        $this->app = $app;
        if (!$app->has('command')) {
            $app->addModule(new CommandModule());
        }

        $app->command->addCommand(
            $this->commandName,
            function (): void {
                $changed = $this->load();
                echo $changed ? 'TypeScript API stub updated.' : 'TypeScript API stub unchanged.';
            },
            'Generate the TypeScript API stub from named router routes',
        );
    }

    /** Safe to call on every application load. Returns true iff the target file was actually replaced. */
    public function load(): bool
    {
        if ($this->app === null) {
            throw new \LogicException('TypeScriptApiGeneratorModule must be added to BraceApp before generation.');
        }

        $routes = [];
        $classes = [];
        $routerPrefix = $this->app->router->getPrefix();
        $apiMountPath = phore_uri('/' . $this->apiMountPath)->clean()->getUri();
        foreach ($this->app->router->getNamedRoutes() as $route) {
            [$methods, $path] = explode('@', $route['route'], 2);
            $path = $this->stripPathPrefix($path, $routerPrefix);
            $path = $this->stripPathPrefix($path, $apiMountPath);
            $path = preg_replace_callback('/::?([A-Za-z_][A-Za-z0-9_]*)/', static fn (array $match): string => '{' . $match[1] . '}', $path) ?? $path;
            $function = $this->parseCallback($route['call']);
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches);
            $pathNames = array_flip($matches[1] ?? []);
            $pathParams = [];
            $queryParams = [];
            foreach ($function->parameters as $parameter) {
                if (isset($pathNames[$parameter->name])) $pathParams[$parameter->name] = $parameter->type;
                else $queryParams[$parameter->name] = $parameter->type;
                $this->collectClasses($parameter->type, $classes, $parameter->nativeType);
            }
            foreach (array_keys($pathNames) as $pathName) {
                if ($function->getParameter((string)$pathName) === null) {
                    throw new \InvalidArgumentException("Path parameter '{$pathName}' is missing from callback for route '{$route['name']}'.");
                }
            }
            $this->collectClasses($function->return->type, $classes, $function->return->nativeType);
            $routes[] = [
                'name' => $route['name'],
                'path' => $path,
                'methods' => array_values(array_unique(array_map('strtoupper', explode('|', $methods)))),
                'response' => $function->return->type,
                'body' => null,
                'pathParams' => $pathParams,
                'queryParams' => $queryParams,
            ];
        }
        ksort($classes);
        return $this->writer->writeIfChanged($this->targetFile, $this->generator->generate($routes, array_values($classes)));
    }

    private function stripPathPrefix(string $path, string $prefix): string
    {
        if (
            $prefix === ''
            || $prefix === '/'
            || ($path !== $prefix && !str_starts_with($path, $prefix . '/'))
        ) {
            return $path;
        }

        // Segmentgrenzen zuerst prüfen, dann die relative URI berechnen.
        $relative = phore_uri($path)->rel($prefix)->getUri();
        if ($relative === '.') {
            return '/';
        }
        return '/' . $relative . (str_ends_with($path, '/') ? '/' : '');
    }

    private function parseCallback(mixed $callback): FunctionSchema
    {
        if (is_array($callback) && count($callback) === 2) {
            return $this->schemaParser->parseMethod($callback[0], (string)$callback[1]);
        }
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('Named API routes must use a callable controller.');
        }
        return $this->schemaParser->parseCallable($callback);
    }

    /** @param array<string,ClassSchema> $classes */
    private function collectClasses(SchemaType $type, array &$classes, ?string $nativeType = null): void
    {
        if ($type instanceof ClassSchema) { $classes[$type->className] = $type; return; }
        if ($type instanceof ClassReferenceSchemaType) {
            $class = $type->className;
            if (!class_exists($class) && $nativeType !== null && class_exists($nativeType)) $class = $nativeType;
            if ($class !== '' && class_exists($class) && !isset($classes[$class])) {
                $schema = $this->schemaParser->parseClass($class);
                $classes[$class] = $schema;
                foreach ($schema->properties as $property) $this->collectClasses($property->type, $classes);
            }
            return;
        }
        if ($type instanceof ArraySchemaType) { $this->collectClasses($type->valueType, $classes); return; }
        if ($type instanceof UnionSchemaType || $type instanceof IntersectionSchemaType) {
            foreach ($type->types as $inner) $this->collectClasses($inner, $classes);
        }
    }
}
