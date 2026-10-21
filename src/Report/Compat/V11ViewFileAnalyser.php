<?php

declare(strict_types=1);

namespace Sonda\Report\Compat;

use SebastianBergmann\CodeCoverage\StaticAnalysis\{FileAnalyser, ParsingFileAnalyser};

/**
 * php-code-coverage 11's analyser, reading each Blade view through the analysis copy of its
 * compiled code: the template itself is not PHP, and the copy declares the function `view` whose
 * branches the report counts.
 */
final readonly class V11ViewFileAnalyser implements FileAnalyser
{
    private ParsingFileAnalyser $analyser;

    /** @param array<string, string> $copies view path => analysis copy */
    public function __construct(private array $copies)
    {
        // The two settings php-code-coverage builds its own analyser with.
        $this->analyser = new ParsingFileAnalyser(true, false);
    }

    public function classesIn(string $filename): array
    {
        return $this->analyser->classesIn($this->source($filename));
    }

    public function traitsIn(string $filename): array
    {
        return $this->analyser->traitsIn($this->source($filename));
    }

    public function functionsIn(string $filename): array
    {
        return $this->analyser->functionsIn($this->source($filename));
    }

    public function linesOfCodeFor(string $filename): array
    {
        return $this->analyser->linesOfCodeFor($this->source($filename));
    }

    /** @return array<int, int> line => block */
    public function executableLinesIn(string $filename): array
    {
        return $this->analyser->executableLinesIn($this->source($filename));
    }

    public function ignoredLinesFor(string $filename): array
    {
        return $this->analyser->ignoredLinesFor($this->source($filename));
    }

    private function source(string $filename): string
    {
        return $this->copies[$filename] ?? $filename;
    }
}
