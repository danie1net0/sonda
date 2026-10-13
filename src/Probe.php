<?php

declare(strict_types=1);

namespace CovProto;

use function str_starts_with;

/**
 * One probe inserted in the source.
 *
 * A probe with a line marks that line as executed when hit. A branch probe is one outcome
 * of a decision (function entry, if/else body, loop body, short-circuit operand, match arm...)
 * and counts toward the branch coverage of its function.
 */
final readonly class Probe
{
    /** @param list<int> $extraLines other lines that share the status of this probe */
    public function __construct(
        public ?int $line,
        public bool $isBranch,
        public string $functionName,
        public int $lineStart,
        public int $lineEnd,
        public array $extraLines = [],
    ) {
    }

    /** @return list<int> */
    public function lines(): array
    {
        $lines = $this->extraLines;

        if ($this->line !== null) {
            $lines[] = $this->line;
        }

        return $lines;
    }

    /** Branches of closures, arrow functions, hooks and file-level code are not reported. */
    public function countsAsBranch(): bool
    {
        return $this->isBranch && !str_starts_with($this->functionName, '{');
    }
}
