<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveParentDelegatingConstructorRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/demo',
        __DIR__.'/vendor',
        // Promoted `public readonly string $reason`: the constructor is public API, not a pure delegation.
        RemoveParentDelegatingConstructorRector::class => [
            __DIR__.'/src/Http/Exception/JsonUploadException.php',
        ],
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
