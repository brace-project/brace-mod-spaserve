<?php

declare(strict_types=1);

namespace spec\Brace\SpaServe\Codegen;

use Brace\Core\BraceApp;
use Brace\Core\EnvironmentType;
use Brace\Router\RouterModule;
use Brace\SpaServe\Codegen\GeneratedFileWriter;
use Brace\SpaServe\Codegen\TypeScriptApiStubGenerator;
use PhpSpec\ObjectBehavior;
use Phore\Schema\Parser\SchemaParser;
use RuntimeException;

final class TypeScriptApiGeneratorModuleSpec extends ObjectBehavior
{
    private string $targetFile;

    public function let(): void
    {
        $this->targetFile = sys_get_temp_dir() . '/brace-spaserve-' . bin2hex(random_bytes(8)) . '.ts';
        $this->beConstructedWith(
            $this->targetFile,
            new SchemaParser(),
            new TypeScriptApiStubGenerator(),
            new GeneratedFileWriter(),
            false,
        );
    }

    public function letGo(): void
    {
        if (is_file($this->targetFile)) unlink($this->targetFile);
    }

    public function it_generates_the_stub_from_named_router_routes(): void
    {
        $app = $this->app();
        $app->router->on('GET@/api/users/:userId', static fn (int $userId): string => (string)$userId, [], 'User.Get');
        $app->router->on('GET@/health', static fn (): string => 'ok');
        $this->register($app);

        $output = $app->command->runCommand('spa-api-build', [], true);
        $generated = file_get_contents($this->targetFile);

        if ($output !== 'TypeScript API stub updated.') throw new RuntimeException('The build command did not report an updated stub.');
        if ($generated === false || !str_contains($generated, '"User.Get": ["GET", "/api/users/{userId}"]')) {
            throw new RuntimeException('The named router route was not generated.');
        }
        if (str_contains($generated, '/health')) throw new RuntimeException('An unnamed router route was generated.');
    }

    public function it_generates_paths_relative_to_the_api_base_url(): void
    {
        $this->beConstructedWith(
            $this->targetFile,
            new SchemaParser(),
            new TypeScriptApiStubGenerator(),
            new GeneratedFileWriter(),
            false,
            'spa-api-build',
            '/api',
        );
        $app = $this->app(appBasePath: '/wurst');
        $app->router->on('GET@/api/users/:userId', static fn (int $userId): string => (string)$userId, [], 'User.Get');
        $this->register($app);
        $this->load()->shouldReturn(true);
        $generated = file_get_contents($this->targetFile);

        if ($generated === false || !str_contains($generated, '"User.Get": ["GET", "/users/{userId}"]')) {
            throw new RuntimeException('The generated route is not relative to the API base URL.');
        }
        if (str_contains($generated, '/wurst/api')) {
            throw new RuntimeException('The application or API mount was embedded in the generated API stub.');
        }
    }

    public function it_does_not_rewrite_unchanged_generated_output(): void
    {
        $app = $this->app();
        $app->router->on('GET@/api/users/:userId', static fn (int $userId): string => (string)$userId, [], 'User.Get');
        $this->register($app);
        $this->load()->shouldReturn(true);
        $this->load()->shouldReturn(false);
    }

    public function it_generates_an_interface_for_a_controller_return_type(): void
    {
        $app = $this->app();
        $app->router->on('GET@/api/greeting', [StubController::class, 'get'], [], 'Greeting.Get');
        $this->register($app);
        $this->load()->shouldReturn(true);
        $generated = file_get_contents($this->targetFile);

        if ($generated === false || !str_contains($generated, 'export interface StubResponse')) {
            throw new RuntimeException('The response DTO interface was not generated.');
        }
    }

    public function it_does_not_auto_generate_during_cli_bootstrap(): void
    {
        $this->beConstructedWith(
            $this->targetFile,
            new SchemaParser(),
            new TypeScriptApiStubGenerator(),
            new GeneratedFileWriter(),
            true,
        );
        $app = $this->app(EnvironmentType::DEVELOPMENT);
        $app->router->on('GET@/api/users/:userId', static fn (int $userId): string => (string)$userId, [], 'User.Get');
        $this->register($app);
        if (is_file($this->targetFile)) throw new RuntimeException('CLI bootstrap generated the stub before spa-api-build was executed.');
    }

    private function app(EnvironmentType $environment = EnvironmentType::PRODUCTION, string $appBasePath = ''): BraceApp
    {
        $app = new BraceApp($environment, $appBasePath);
        $app->addModule(new RouterModule());
        return $app;
    }
}

final class StubController
{
    /** @return StubResponse Generated response. */
    public function get(): StubResponse { return new StubResponse(); }
}

final class StubResponse
{
    public string $message;
}
