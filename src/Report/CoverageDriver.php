<?php

declare(strict_types=1);

namespace Sonda\Report;

use Sonda\Cli\Version;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Driver;

use function dirname;

/**
 * php-code-coverage needs a driver to build a report, but sonda collects nothing through
 * it: the data comes from a finished run and is appended to the report.
 */
final class CoverageDriver extends Driver
{
    public function name(): string
    {
        return 'sonda';
    }

    /** The name and the version php-code-coverage 14 writes into a `.cov` file. */
    public function version(): string
    {
        return Version::of(dirname(__DIR__, 2));
    }

    public function nameAndVersion(): string
    {
        return 'sonda';
    }

    /** Lets the reports of php-code-coverage 11 and 12 show the branch columns. */
    public function canCollectBranchAndPathCoverage(): bool
    {
        return true;
    }

    public function start(): void
    {
    }

    public function stop(): RawCodeCoverageData
    {
        return RawCodeCoverageData::fromXdebugWithPathCoverage([]);
    }

    /** Lets the reports of php-code-coverage 14 show the branch columns. */
    protected function canCollectBranchCoverage(): bool
    {
        return true;
    }

    /** php-code-coverage 14 asks for paths too before it counts branches. */
    protected function canCollectPathCoverage(): bool
    {
        return true;
    }
}
