<?php

declare(strict_types=1);

namespace Sonda\Report;

use InvalidArgumentException;
use Sonda\Run;
use SebastianBergmann\CodeCoverage\Report\PHP;

/** Writes a run as a php-code-coverage `.cov` file, which every report format can read. */
final class ReportWriter
{
    /**
     * @param list<string> $sourceDirectories
     * @param list<string> $excludedDirectories
     */
    public static function write(Run $run, array $sourceDirectories, array $excludedDirectories, string $outputFile): void
    {
        if ($outputFile === '') {
            throw new InvalidArgumentException('The report path cannot be empty.');
        }

        $sourceFiles = SourceFiles::findAll($sourceDirectories, $excludedDirectories);

        new PHP()->process(CoverageBuilder::build($run, $sourceFiles), $outputFile);
    }
}
