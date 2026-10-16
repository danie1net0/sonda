<?php

declare(strict_types=1);

namespace Sonda\Report;

use Sonda\Filesystem;
use SebastianBergmann\CodeCoverage\StaticAnalysis\{AnalysisResult, SourceAnalyser};

/**
 * Analyses a Blade view by the analysis copy of its compiled code: the template itself is not
 * PHP, and the copy declares the function `view` that the report counts the branches of.
 */
final readonly class ViewSourceAnalyser implements SourceAnalyser
{
    /** @param array<string, string> $copies view path => path of its analysis copy */
    public function __construct(private array $copies, private SourceAnalyser $analyser)
    {
    }

    public function analyse(string $sourceCodeFile, string $sourceCode, bool $useAnnotationsForIgnoringCode, bool $ignoreDeprecatedCode): AnalysisResult
    {
        if (isset($this->copies[$sourceCodeFile])) {
            $sourceCode = Filesystem::read($this->copies[$sourceCodeFile]);
        }

        return $this->analyser->analyse($sourceCodeFile, $sourceCode, $useAnnotationsForIgnoringCode, $ignoreDeprecatedCode);
    }
}
