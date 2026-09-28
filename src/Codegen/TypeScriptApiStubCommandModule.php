<?php

declare(strict_types=1);

namespace Brace\SpaServe\Codegen;

use Brace\Core\BraceApp;
use Brace\Core\BraceModule;
use Brace\Core\EnvironmentType;

/**
 * Connects a configured TypeScript API stub to Brace Command.
 *
 * The command is always available so production builds can generate the stub explicitly.
 * Automatic generation is restricted to Brace's development environment and can be disabled.
 *
 * @see TypeScriptApiStubModule
 */
final class TypeScriptApiStubCommandModule implements BraceModule
{
    /**
     * Configure command registration and optional development-time generation.
     *
     * @param TypeScriptApiStubModule $api Fully configured API contract and target file.
     * @param bool $autoGenerateInDevelopment Generate once when the Brace app is loaded in development mode.
     * @param string $commandName Brace command used for explicit generation.
     * @see TypeScriptApiStubModule::load()
     *
     * @example $app->addModule(new TypeScriptApiStubCommandModule($api, autoGenerateInDevelopment: true));
     */
    public function __construct(
        private readonly TypeScriptApiStubModule $api,
        private readonly bool $autoGenerateInDevelopment = true,
        private readonly string $commandName = 'spa-api-build',
    ) {
    }

    /**
     * Register the explicit build command and optionally generate during development bootstrap.
     *
     * The explicit command works in every environment. The automatic call to the generator only
     * runs when both auto-generation is enabled and the app environment is DEVELOPMENT.
     *
     * @param BraceApp $app Brace application with Brace\Command\CommandModule already registered.
     * @return void
     * @see \Brace\Command\Command::addCommand()
     *
     * @example $app->addModule(new TypeScriptApiStubCommandModule($api, autoGenerateInDevelopment: false));
     */
    public function register(BraceApp $app): void
    {
        $app->command->addCommand(
            $this->commandName,
            function (): void {
                $changed = $this->api->load();
                echo $changed ? 'TypeScript API stub updated.' : 'TypeScript API stub unchanged.';
            },
            'Generate the TypeScript API stub',
        );

        if (!$this->autoGenerateInDevelopment || $app->environmentType !== EnvironmentType::DEVELOPMENT) {
            return;
        }

        $this->api->load();
    }
}
