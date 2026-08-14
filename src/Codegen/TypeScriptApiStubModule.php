<?php

declare(strict_types=1);

namespace Brace\SpaServe\Codegen;

use Phore\Schema\Parser\SchemaParser;
use Phore\Schema\Schema\ClassSchema;
use Phore\Schema\Schema\Type\ArraySchemaType;
use Phore\Schema\Schema\Type\ClassReferenceSchemaType;
use Phore\Schema\Schema\Type\IntersectionSchemaType;
use Phore\Schema\Schema\Type\SchemaType;
use Phore\Schema\Schema\Type\UnionSchemaType;

final class TypeScriptApiStubModule
{
    /** @var list<array{name:string,path:string,methods:list<string>,callback:callable,bodyParameter:?string}> */
    private array $routes = [];

    public function __construct(
        private readonly string $targetFile,
        private readonly SchemaParser $schemaParser = new SchemaParser(),
        private readonly TypeScriptApiStubGenerator $generator = new TypeScriptApiStubGenerator(),
        private readonly GeneratedFileWriter $writer = new GeneratedFileWriter(),
    ) {}

    /** $bodyParameter explicitly marks one callback argument as JSON request body; all other non-path args become query params. */
    public function route(string $name, string $path, string|array $methods, callable $callback, ?string $bodyParameter = null): self
    {
        $methods = array_values(array_unique(array_map('strtoupper', (array)$methods)));
        if ($methods === []) throw new \InvalidArgumentException('At least one HTTP method is required.');
        $this->routes[] = compact('name', 'path', 'methods', 'callback', 'bodyParameter');
        return $this;
    }

    /** Safe to call on every application load. Returns true iff the target file was actually replaced. */
    public function load(): bool
    {
        $routes = []; $classes = [];
        foreach ($this->routes as $route) {
            $function = $this->schemaParser->parseCallable($route['callback']);
            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $route['path'], $matches);
            $pathNames = array_flip($matches[1] ?? []);
            $pathParams = []; $queryParams = []; $body = null; $bodyFound = $route['bodyParameter'] === null;
            foreach ($function->parameters as $parameter) {
                if (isset($pathNames[$parameter->name])) $pathParams[$parameter->name] = $parameter->type;
                elseif ($route['bodyParameter'] === $parameter->name) { $body = $parameter->type; $bodyFound = true; }
                else $queryParams[$parameter->name] = $parameter->type;
                $this->collectClasses($parameter->type, $classes);
            }
            if (!$bodyFound) throw new \InvalidArgumentException("Body parameter '{$route['bodyParameter']}' does not exist on route '{$route['name']}'.");
            foreach (array_keys($pathNames) as $pathName) if ($function->getParameter((string)$pathName) === null) throw new \InvalidArgumentException("Path parameter '{$pathName}' is missing from callback for route '{$route['name']}'.");
            $this->collectClasses($function->return->type, $classes);
            $routes[] = ['name'=>$route['name'],'path'=>$route['path'],'methods'=>$route['methods'],'response'=>$function->return->type,'body'=>$body,'pathParams'=>$pathParams,'queryParams'=>$queryParams];
        }
        ksort($classes);
        return $this->writer->writeIfChanged($this->targetFile, $this->generator->generate($routes, array_values($classes)));
    }

    /** @param array<string,ClassSchema> $classes */
    private function collectClasses(SchemaType $type, array &$classes): void
    {
        if ($type instanceof ClassSchema) { $classes[$type->className] = $type; return; }
        if ($type instanceof ClassReferenceSchemaType) {
            $class = (string)($type->toArray()['className'] ?? '');
            if ($class !== '' && class_exists($class) && !isset($classes[$class])) {
                $schema = $this->schemaParser->parseClass($class); $classes[$class] = $schema;
                foreach ($schema->properties as $property) $this->collectClasses($property->type, $classes);
            }
            return;
        }
        if ($type instanceof ArraySchemaType) { $this->collectClasses($type->valueType, $classes); return; }
        if ($type instanceof UnionSchemaType || $type instanceof IntersectionSchemaType) foreach ($type->types as $inner) $this->collectClasses($inner, $classes);
    }
}
