<?php

declare(strict_types=1);

namespace Sonda\Report;

use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;

/**
 * php-code-coverage needs a driver to build a report, but sonda collects nothing through
 * it: the data comes from a finished run and is appended to the report.
 */
final class CoverageDriver extends Driver
{
    public function nameAndVersion(): string
    {
        return 'sonda';
    }

    /** Lets the reports show the branch columns. */
    public function canCollectBranchAndPathCoverage(): bool
    {
        return true;
    }

    public function start(): void
    {
    }

    public function stop(): RawCodeCoverageData
    {
        return RawCodeCoverageData::fromXdebugWithoutPathCoverage([]);
    }
}
