<?php

declare(strict_types=1);

namespace Sonda\Project;

/** One `require` or `include` of a file of the scope, found in another file of the scope. */
final readonly class DirectRequire
{
    public function __construct(public string $file, public int $line, public string $target)
    {
    }
}
