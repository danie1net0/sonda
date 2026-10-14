<?php

declare(strict_types=1);

namespace Sonda;

/**
 * Text inserted at (or replacing a range starting at) a byte offset of the original source.
 *
 * Edits at the same offset are applied closing ones first, then by order, then in the order
 * they were created (the sort is stable). See InstrumentingVisitor for how orders are chosen.
 */
final readonly class Edit
{
    public function __construct(
        public int $position,
        public bool $isOpening,
        public int $order,
        public string $text,
        public int $deleteLength = 0,
    ) {
    }

    /** Text that goes before whatever else starts at the same offset (in creation order). */
    public static function opening(int $position, string $text, int $deleteLength = 0): self
    {
        return new self($position, true, 0, $text, $deleteLength);
    }

    /** Text that goes after whatever ends at the same offset: the lower the order, the earlier. */
    public static function closing(int $position, int $order, string $text): self
    {
        return new self($position, false, $order, $text);
    }

    public static function compare(self $first, self $second): int
    {
        return [$first->position, $first->isOpening, $first->order]
            <=> [$second->position, $second->isOpening, $second->order];
    }
}
