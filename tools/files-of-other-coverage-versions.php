<?php

declare(strict_types=1);

// The compatibility files written for the php-code-coverage versions that are not installed, as
// CoverageApis tells the installed one: 11, 12 or 14. Neither the suite nor PHPStan can check
// them against the installed version, so the coverage gate and the analysis leave them out.

use SebastianBergmann\CodeCoverage\StaticAnalysis\{Registry, SourceAnalyser};

$otherVersions = [
    ['src/Report/Compat/V12CoverageApi.php', 'src/Report/Compat/V14CoverageApi.php', 'src/Report/Compat/ObjectCoverageData.php', 'src/Report/ViewSourceAnalyser.php'],
    ['src/Report/Compat/V11CoverageApi.php', 'src/Report/Compat/V11ViewFileAnalyser.php', 'src/Report/Compat/V14CoverageApi.php'],
    ['src/Report/Compat/V11CoverageApi.php', 'src/Report/Compat/V11ViewFileAnalyser.php', 'src/Report/Compat/V12CoverageApi.php', 'src/Report/Compat/Coverage12AndOlder.php'],
];

return $otherVersions[(int) interface_exists(SourceAnalyser::class) + (int) class_exists(Registry::class)];
