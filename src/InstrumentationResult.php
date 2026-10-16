<?php

declare(strict_types=1);

namespace Sonda;

/**
 * The instrumented code of a file and its probes. For a Blade view, the probes refer to the
 * lines of the compiled PHP: the line map leads back to the template, and the plain compiled
 * code is what the report analyses.
 */
final readonly class InstrumentationResult
{
    /**
     * @param array<int, Probe>   $probes
     * @param array<int, int>|null $lineMap      compiled line => template line, for a view
     * @param string|null          $analysisCode the plain compiled code, for a view
     */
    public function __construct(
        public string $code,
        public array $probes,
        public ?array $lineMap = null,
        public ?string $analysisCode = null,
    ) {
    }
}
