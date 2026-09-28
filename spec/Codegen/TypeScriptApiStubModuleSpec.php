<?php

declare(strict_types=1);

namespace spec\Brace\SpaServe\Codegen;

use Brace\Core\BraceApp;
use Brace\Core\EnvironmentType;
use Brace\SpaServe\Codegen\GeneratedFileWriter;
use Brace\SpaServe\Codegen\TypeScriptApiStubGenerator;
use PhpSpec\ObjectBehavior;
use Phore\Schema\Parser\SchemaParser;
use RuntimeException;

final class TypeScriptApiStubModuleSpec extends ObjectBehavior
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
        if (is_file($this->targetFile)) {
            unlink($this->targetFile);
        }
    }

    public function it_registers_the_build_command_and_generates_the_stub(): void
    {
        $this->route(
            name: 'User.Get',
            path: '/api/users/{userId}',
            methods: 'GET',
            callback: static fn (int $userId): string => (string) $userId,
        );

        $app = new BraceApp(EnvironmentType::PRODUCTION);
        $this->register($app);

        if (!$app->has('command')) {
            throw new RuntimeException('The stub module did not register Brace Command.');
        }

        $output = $app->command->runCommand('spa-api-build', returnOutput: true);

        if ($output !== 'TypeScript API stub updated.') {
            throw new RuntimeException('The build command did not report an updated stub.');
        }

        if (!is_file($this->targetFile)) {
            throw new RuntimeException('The build command did not create the configured target file.');
        }
    }

    public function it_does_not_rewrite_unchanged_generated_output(): void
    {
        $this->route(
            name: 'User.Get',
            path: '/api/users/{userId}',
            methods: 'GET',
            callback: static fn (int $userId): string => (string) $userId,
        );

        $this->load()->shouldReturn(true);
        $this->load()->shouldReturn(false);
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
        $this->route(
            name: 'User.Get',
            path: '/api/users/{userId}',
            methods: 'GET',
            callback: static fn (int $userId): string => (string) $userId,
        );

        $app = new BraceApp(EnvironmentType::DEVELOPMENT);
        $this->register($app);

        if (is_file($this->targetFile)) {
            throw new RuntimeException('CLI bootstrap generated the stub before spa-api-build was executed.');
        }
    }
}
