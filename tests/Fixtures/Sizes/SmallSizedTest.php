<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sizes;

use PHPUnit\Framework\Attributes\Small;

/** A test class of small size, for the size a test's coverage carries. */
#[Small]
final class SmallSizedTest
{
    public function test_it(): void
    {
    }
}
