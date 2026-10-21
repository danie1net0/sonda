<?php

declare(strict_types=1);

namespace Sonda\Report\Compat;

use Sonda\Report\ViewSourceAnalyser;
use SebastianBergmann\CodeCoverage\Data\{ProcessedBranchCoverageData, ProcessedCodeCoverageData, ProcessedFunctionCoverageData};
use SebastianBergmann\CodeCoverage\Node\File;
use SebastianBergmann\CodeCoverage\StaticAnalysis\{FileAnalyser, ParsingSourceAnalyser};

/**
 * php-code-coverage 12.
 *
 * @phpstan-import-type Branch from CoverageApi
 */
final class V12CoverageApi implements CoverageApi
{
    public function analyser(array $copies): FileAnalyser
    {
        // The two settings php-code-coverage builds its own analyser with.
        return new FileAnalyser(new ViewSourceAnalyser($copies, new ParsingSourceAnalyser()), true, false);
    }

    public function executableLines(object $analyser, string $file): array
    {
        /** @var FileAnalyser $analyser the one analyser() gave */
        /** @var non-empty-string $file a path php-code-coverage keys its data by */
        return $analyser->analyse($file)->executableLines();
    }

    public function branches(mixed $function): array
    {
        /** @var ProcessedFunctionCoverageData $function */
        $branches = [];

        foreach ($function->branches as $id => $branch) {
            $data = [];
            $data['op_start'] = $branch->op_start;
            $data['op_end'] = $branch->op_end;
            $data['line_start'] = $branch->line_start;
            $data['line_end'] = $branch->line_end;
            $data['hit'] = $branch->hit;
            $data['out'] = $branch->out;
            $data['out_hit'] = $branch->out_hit;
            $branches[$id] = $data;
        }

        return $branches;
    }

    public function withBranches(mixed $function, array $branches): ProcessedFunctionCoverageData
    {
        /** @var ProcessedFunctionCoverageData $function */
        $objects = [];

        foreach ($branches as $id => $branch) {
            $objects[$id] = new ProcessedBranchCoverageData($branch['op_start'], $branch['op_end'], $branch['line_start'], $branch['line_end'], $branch['hit'], $branch['out'], $branch['out_hit']);
        }

        return new ProcessedFunctionCoverageData($objects, $function->paths);
    }

    public function setFunctionCoverage(ProcessedCodeCoverageData $data, array $functions): void
    {
        /** @var array<string, array<string, ProcessedFunctionCoverageData>> $functions what withBranches() gives */
        $data->setFunctionCoverage($functions);
    }

    public function functionSpans(File $node): array
    {
        $spans = [];

        foreach ($node->functions() as $name => $function) {
            $spans[$name] = [$function->startLine, $function->endLine];
        }

        foreach ([...$node->classes(), ...$node->traits()] as $className => $class) {
            foreach ($class->methods as $methodName => $method) {
                $spans[$className . '->' . $methodName] = [$method->startLine, $method->endLine];
            }
        }

        return $spans;
    }
}
