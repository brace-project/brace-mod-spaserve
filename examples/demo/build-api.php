<?php

declare(strict_types=1);

use Brace\Command\CommandModule;
use Brace\Core\BraceApp;
use Brace\Core\EnvironmentType;
use Brace\SpaServe\Codegen\TypeScriptApiStubCommandModule;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = new BraceApp(EnvironmentType::PRODUCTION);
$app->addModule(new CommandModule());

$contract = require __DIR__ . '/api-stub.php';

$app->addModule(new TypeScriptApiStubCommandModule(
    api: $contract['api'],
    autoGenerateInDevelopment: false,
));
$app->command->runCommand('spa-api-build');
