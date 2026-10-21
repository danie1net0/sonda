<?php

declare(strict_types=1);

namespace Sonda\Report\Compat;

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Node\File;

/**
 * What differs between php-code-coverage 11 (PHPUnit 11, Pest 3) and 12 (PHPUnit 12, Pest 4) in
 * the parts sonda builds its reports from: the static analyser, the processed branch data, which
 * 12 keeps in objects and 11 in arrays, and the code units of a report node.
 *
 * @phpstan-type Branch array{op_start: int, op_end: int, line_start: int, line_end: int, hit: list<string>, out: array<int, int>, out_hit: array<int, int>}
 */
interface CoverageApi
{
    /**
     * The file analyser to give php-code-coverage, which reads each view through its analysis copy.
     *
     * @param array<string, string> $copies view path => analysis copy
     */
    public function analyser(array $copies): object;

    /**
     * The executable lines of a file, each with the block php-code-coverage marks executed together.
     *
     * @return array<int, int> line => block
     */
    public function executableLines(object $analyser, string $file): array;

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
}
