<?php

declare(strict_types=1);

namespace Sonda;

final readonly class InstrumentationResult
{
    /** @param array<int, Probe> $probes */
    public function __construct(
        public string $code,
        public array $probes,
    ) {
    }
}
