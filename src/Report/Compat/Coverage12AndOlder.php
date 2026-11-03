<?php

declare(strict_types=1);

namespace Sonda\Report\Compat;

use Closure;
use ReflectionProperty;
use Sonda\Report\CoverageDriver;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Report\PHP;

/**
 * php-code-coverage 11 and 12: a report turns branches on itself and keeps its analyser in a
 * property, the tests of a line or a branch are a list of test ids, the report writers take the
 * coverage itself, and `Report\PHP` writes the `.cov` files.
 */
trait Coverage12AndOlder
{
    public function enableBranches(CodeCoverage $coverage, CoverageDriver $driver): void
    {
        $coverage->enableBranchAndPathCoverage();
    }

    public function useAnalyser(CodeCoverage $coverage, object $analyser): void
    {
        // php-code-coverage builds its analyser itself, with the same two settings analyser() uses.
        new ReflectionProperty(CodeCoverage::class, 'analyser')->setValue($coverage, $analyser);
    }

    public function testHits(ProcessedCodeCoverageData $data, array $tests): Closure
    {
        return static fn (array $tests): array => $tests;
    }

    public function mergedHits(array $first, array $second): array
    {
        /** @var list<string> $first the ids of the tests, in this version */
        /** @var list<string> $second */
        return array_values(array_unique([...$first, ...$second]));
    }

    public function reportInput(CodeCoverage $coverage): CodeCoverage
    {
        return $coverage;
    }

    public function writeCov(CodeCoverage $coverage, string $file): void
    {
        /** @var non-empty-string $file the report writers refuse an empty path */
        new PHP()->process($coverage, $file);
    }
}
