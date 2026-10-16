<?php

declare(strict_types=1);

namespace Sonda\Blade;

use Sonda\{Filesystem, InstrumentationResult, Instrumenter};

/**
 * Instruments a Blade view through its compiled PHP: the probes refer to the compiled lines,
 * and the result carries the line map back to the template and the plain compiled code the
 * report analyses.
 */
final readonly class ViewInstrumenter
{
    public function __construct(private Instrumenter $instrumenter)
    {
    }

    /** @param callable(string): string $compile the Blade compiler, from template to PHP */
    public function instrument(string $viewPath, string $compiledPath, string $fileId, callable $compile): InstrumentationResult
    {
        $template = Filesystem::read($viewPath);
        $compiled = $compile($template);
        $result = $this->instrumenter->instrumentCode($compiled, $compiledPath, $fileId);

        return new InstrumentationResult($result->code, $result->probes, LineMap::of($template, $compiled, $compile), $compiled);
    }
}
