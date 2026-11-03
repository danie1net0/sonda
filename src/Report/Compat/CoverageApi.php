<?php

declare(strict_types=1);

namespace Sonda\Report\Compat;

use Closure;
use Sonda\Report\CoverageDriver;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Node\File;

/**
 * What differs between php-code-coverage 11 (PHPUnit 11, Pest 3), 12 (PHPUnit 12, Pest 4) and 14
 * (PHPUnit 13, Pest 5) in the parts sonda builds its reports from: the static analyser and how a
 * report gets it, the processed branch data, which 12 and 14 keep in objects and 11 in arrays, the
 * tests of a line or a branch, which 14 keeps as indexes, the code units of a report node and the
 * writer of `.cov` files.
 *
 * @phpstan-type Branch array{op_start: int, op_end: int, line_start: int, line_end: int, hit: array<mixed>, out: array<int, int>, out_hit: array<int, int>}
 */
interface CoverageApi
{
    /**
     * The file analyser to give php-code-coverage, which reads each view through its analysis copy.
     *
     * @param array<string, string> $copies view path => analysis copy
     */
    public function analyser(array $copies): object;

    /** Makes a report count branches and paths, which sonda's driver reports for every file. */
    public function enableBranches(CodeCoverage $coverage, CoverageDriver $driver): void;

    /** Makes a report analyse its files with the analyser analyser() gave, in place of its own. */
    public function useAnalyser(CodeCoverage $coverage, object $analyser): void;

    /**
     * The executable lines of a file, each with the block php-code-coverage marks executed together.
     *
     * @return array<int, int> line => block
     */
    public function executableLines(object $analyser, string $file): array;

    /**
     * Turns lists of test ids into the tests of a line or a branch, of the kind php-code-coverage
     * keeps in the data: every test the lists name must be among the given tests.
     *
     * @param list<string> $tests every test the lists will name
     *
     * @return Closure(list<string>): array<mixed>
     */
    public function testHits(ProcessedCodeCoverageData $data, array $tests): Closure;

    /**
     * The tests of two lines that become one, each test once.
     *
     * @param array<mixed> $first  the tests of a line, of the kind php-code-coverage keeps
     * @param array<mixed> $second
     *
     * @return array<mixed>
     */
    public function mergedHits(array $first, array $second): array;

    /**
     * The branches of the processed data of a function.
     *
     * @return array<int, Branch> by branch id
     */
    public function branches(mixed $function): array;

    /**
     * The processed data of a function with other branches, of the kind php-code-coverage keeps.
     *
     * @param array<int, Branch> $branches
     */
    public function withBranches(mixed $function, array $branches): mixed;

    /**
     * Puts the processed data of the functions back into the data of a report.
     *
     * @param array<string, array<string, mixed>> $functions by file and function, of the kind withBranches() gives
     */
    public function setFunctionCoverage(ProcessedCodeCoverageData $data, array $functions): void;

    /**
     * The functions and the methods of classes and traits a report node counts, with their spans:
     * `Class->method` for methods, the functions by name.
     *
     * @return array<string, array{0: int, 1: int}> name => [first line, last line]
     */
    public function functionSpans(File $node): array;

    /**
     * What the HTML, Clover and Cobertura writers of php-code-coverage take: the coverage itself in
     * 11 and 12, the tree of its report in 14.
     */
    public function reportInput(CodeCoverage $coverage): object;

    /** Writes a report as a `.cov` file, which every report format of php-code-coverage can read. */
    public function writeCov(CodeCoverage $coverage, string $file): void;
}
