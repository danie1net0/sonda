<?php

declare(strict_types=1);

namespace Sonda\Report;

use Sonda\Run;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\PHP;

/** Writes a run as a php-code-coverage `.cov` file, which every report format can read. */
final class ReportWriter
{
    /** @param list<string> $excludedDirectories */
    public static function write(Run $run, string $sourceDirectory, array $excludedDirectories, string $outputFile): void
    {
        $sourceFiles = SourceFiles::find($sourceDirectory, $excludedDirectories);
        $filter      = new Filter;
        $filter->includeFiles($sourceFiles);

        // Branch data needs no flag: php-code-coverage keeps whatever the raw data brings.
        $codeCoverage = new CodeCoverage(new CoverageDriver, $filter);
        $codeCoverage->append(RawCoverageBuilder::build($run, $sourceFiles), 'sonda');

        (new PHP)->process($codeCoverage, $outputFile);
    }
}
