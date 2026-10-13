<?php

declare(strict_types=1);

namespace Fixture\Project\Tests;

use Fixture\Project\Discount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscountTest extends TestCase
{
    /** Runs before any test: what it executes must not count as covered. */
    public static function quantities(): array
    {
        return [[20, Discount::describe(10)]];
    }

    #[DataProvider('quantities')]
    public function testLargeOrdersGetTheMaximumRate(int $quantity, string $description): void
    {
        $this->assertSame(10, Discount::rate($quantity));
        $this->assertSame('10%', $description);
    }
}
