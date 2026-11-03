<?php

declare(strict_types=1);

namespace Sonda\Report\Compat;

use SebastianBergmann\CodeCoverage\StaticAnalysis\{Registry, SourceAnalyser};

use function class_exists;

/**
 * The php-code-coverage the project has installed: 12 has the SourceAnalyser that 11 lacks, and 14
 * the Registry that 12 lacks.
 */
final class CoverageApis
{
    /** @var array{0: class-string<CoverageApi>, 1: class-string<CoverageApi>, 2: class-string<CoverageApi>} by how many of the two are installed */
    private const array APIS = [V11CoverageApi::class, V12CoverageApi::class, V14CoverageApi::class];

    public static function current(): CoverageApi
    {
        // A lookup, not a condition: each test run sees one version, and a branch would show one side untested.
        $api = self::APIS[(int) interface_exists(SourceAnalyser::class) + (int) class_exists(Registry::class)];

        return new $api();
    }
}
