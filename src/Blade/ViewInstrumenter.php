<?php

declare(strict_types=1);

namespace Sonda\Blade;

use Sonda\{Filesystem, InstrumentationResult, Instrumenter};

/**
 * Instruments a Blade view through its compiled PHP: the probes refer to the compiled lines and
 * belong to the function `view`, and the result carries the line map back to the template and
 * the analysis copy of the compiled code, which declares that function for the report.
 */
final readonly class ViewInstrumenter
{
    /** The function the report counts the branches of a view by. */
    public const string FUNCTION = 'view';

    public function __construct(private Instrumenter $instrumenter)
    {
    }

    /** @param callable(string): string $compile the Blade compiler, from template to PHP */
    public function instrument(string $viewPath, string $compiledPath, string $fileId, callable $compile): InstrumentationResult
    {
        $template = Filesystem::read($viewPath);
        $compiled = $compile($template);
        $result = $this->instrumenter->instrumentCode($compiled, $compiledPath, $fileId, self::FUNCTION);

        return new InstrumentationResult($result->code, $result->probes, LineMap::of($template, $compiled, $compile), AnalysisCopy::of($compiled, $template));
    }
}
