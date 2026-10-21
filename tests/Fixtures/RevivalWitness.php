<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/** An object that says when unserialize revives it. */
final class RevivalWitness
{
    public static bool $revived = false;

    public function __wakeup(): void
    {
        self::$revived = true;
    }
}
