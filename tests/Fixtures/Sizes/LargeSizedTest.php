<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sizes;

use PHPUnit\Framework\Attributes\Large;

/** A test class of large size, for the size a test's coverage carries. */
#[Large]
final class LargeSizedTest
{
    public function test_it(): void
    {
    }
}
