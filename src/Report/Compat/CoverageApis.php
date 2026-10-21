<?php

declare(strict_types=1);

namespace Sonda\Report\Compat;

use SebastianBergmann\CodeCoverage\StaticAnalysis\SourceAnalyser;

use function interface_exists;

/** The php-code-coverage the project has installed: 12 has the SourceAnalyser that 11 lacks. */
final class CoverageApis
{
    /** @var array{0: class-string<CoverageApi>, 1: class-string<CoverageApi>} by whether 12 is installed */
    private const array APIS = [V11CoverageApi::class, V12CoverageApi::class];

    public static function current(): CoverageApi
    {
        // A lookup, not a condition: each test run sees one version, and a branch would show one side untested.
        $api = self::APIS[(int) interface_exists(SourceAnalyser::class)];

        return new $api();
    }
}
