<?php

declare(strict_types=1);

namespace Sonda\Report;

/**
 * What a report leaves uncovered within the part of it that a change touches, and the size of
 * that part.
 *
 * @phpstan-import-type Gaps from Uncovered
 */
final readonly class ChangeCoverage
{
    /** @param array<string, Gaps> $uncovered by file, only the files with gaps */
    public function __construct(
        public array $uncovered,
        public int $executableLines,
        public int $executedLines,
        public int $executableBranches,
        public int $executedBranches,
    ) {
    }
}
