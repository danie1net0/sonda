<?php

declare(strict_types=1);

namespace Fixture\Project;

final class Shipping
{
    public static function cost(int $distance): int
    {
        return $distance > 100 ? 30 : 10;
    }
}
