<?php

declare(strict_types=1);

namespace Sonda\Report;

use Sonda\Run;
use SebastianBergmann\CodeCoverage\{CodeCoverage, Filter};

/** A php-code-coverage object holding a run, ready for any of its reports. */
final class CoverageBuilder
{
    /** @param list<string> $sourceFiles only these files enter the report, covered or not */
    public static function build(Run $run, array $sourceFiles): CodeCoverage
    {
        $filter = new Filter();
        $filter->includeFiles($sourceFiles);

        $coverage = new CodeCoverage(new CoverageDriver(), $filter);
        $coverage->enableBranchAndPathCoverage();
        $coverage->append(RawCoverageBuilder::build($run, $sourceFiles), 'sonda');

        return $coverage;
    }
}
