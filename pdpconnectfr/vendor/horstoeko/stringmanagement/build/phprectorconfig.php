<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../src',
        __DIR__ . '/../tests',
    ])
    ->withSkip([
        __DIR__ . '/../src/entities',
        __DIR__ . '/../src/codelistsenum',
    ])
    ->withSkip([
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,
    ])
    ->withPhp73Sets()
    ->withPreparedSets(
        codeQuality: true,
        codingStyle: true,
        strictBooleans: true,
        instanceOf: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
        privatization: true,
        deadCode: true,
        //
        carbon: false,
        doctrineCodeQuality: false,
        naming: false,
        rectorPreset: false,
        symfonyCodeQuality: false,
        symfonyConfigs: false,
        typeDeclarations: false,
    );