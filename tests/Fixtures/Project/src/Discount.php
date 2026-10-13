<?php

declare(strict_types=1);

namespace Fixture\Project;

final class Discount
{
    public static function rate(int $quantity): int
    {
        if ($quantity >= 10) {
            return 10;
        }

        return 0;
    }

    public static function describe(int $rate): string
    {
        return "{$rate}%";
    }
}
