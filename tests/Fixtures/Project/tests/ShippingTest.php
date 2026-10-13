<?php

declare(strict_types=1);

namespace Fixture\Project\Tests;

use Fixture\Project\Shipping;
use PHPUnit\Framework\TestCase;

final class ShippingTest extends TestCase
{
    public function testShortDistancesCostTheMinimum(): void
    {
        $this->assertSame(10, Shipping::cost(5));
    }
}
