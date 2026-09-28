<?php

declare(strict_types=1);

use Brace\Command\CommandModule;
use Brace\Core\BraceApp;
use Brace\Core\EnvironmentType;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = new BraceApp(EnvironmentType::PRODUCTION);
$app->addModule(new CommandModule());

$contract = require __DIR__ . '/api-stub.php';

$app->addModule($contract['api']);
$app->command->runCommand('spa-api-build');
