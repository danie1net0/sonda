<?php

declare(strict_types=1);

namespace Sonda\Report\Compat;

use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Node\File;

/**
 * php-code-coverage 11, which keeps the processed branch data and the code units in arrays.
 *
 * @phpstan-import-type Branch from CoverageApi
 */
final class V11CoverageApi implements CoverageApi
{
    public function analyser(array $copies): V11ViewFileAnalyser
    {
        return new V11ViewFileAnalyser($copies);
    }

    public function executableLines(object $analyser, string $file): array
    {
        /** @var V11ViewFileAnalyser $analyser the one analyser() gave */
        return $analyser->executableLinesIn($file);
    }

    public function branches(mixed $function): array
    {
        /** @var array{branches: array<int, Branch>} $function */
        return $function['branches'];
    }

    /** @return array<string, mixed> */
    public function withBranches(mixed $function, array $branches): array
    {
        /** @var array{branches: array<int, Branch>, paths: array<int, mixed>} $function */
        $function['branches'] = $branches;

        return $function;
    }

    public function setFunctionCoverage(ProcessedCodeCoverageData $data, array $functions): void
    {
        $data->setFunctionCoverage($functions);
    }

    public function functionSpans(File $node): array
    {
        $spans = [];

        /** @var array<string, array{startLine: int, endLine: int}> $functions */
        $functions = $node->functions();

        foreach ($functions as $name => $function) {
            $spans[$name] = [$function['startLine'], $function['endLine']];
        }

        /** @var array<string, array{methods: array<string, array{startLine: int, endLine: int}>}> $classes */
        $classes = [...$node->classes(), ...$node->traits()];

        foreach ($classes as $className => $class) {
            foreach ($class['methods'] as $methodName => $method) {
                $spans[$className . '->' . $methodName] = [$method['startLine'], $method['endLine']];
            }
        }

        return $spans;
    }
}
