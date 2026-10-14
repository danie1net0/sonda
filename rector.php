<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\InlineArrayReturnAssignRector;
use Rector\CodingStyle\Rector\Use_\SeparateMultiUseImportsRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/autoload.php',
        __DIR__ . '/bootstrap.php',
        __DIR__ . '/report.php',
    ])
    ->withSkip([
        __DIR__ . '/tests/Fixtures',
        InlineArrayReturnAssignRector::class,
        // Pint groups the imports: letting Rector split them again would never settle.
        SeparateMultiUseImportsRector::class,
    ])
    ->withPhpSets(php84: true)
    ->withSets([
        SetList::DEAD_CODE,
        SetList::CODE_QUALITY,
        SetList::CODING_STYLE,
        SetList::TYPE_DECLARATION,
        SetList::PRIVATIZATION,
    ])
    ->withImportNames(
        removeUnusedImports: true,
    );
