<?php

declare(strict_types=1);

namespace Sonda\Cli;

/** What the test runner is called with, and the phpunit.xml written for it when the paths asked for one. */
final readonly class RunnerInvocation
{
    /** @param list<string> $arguments */
    public function __construct(public array $arguments, public ?string $generatedConfiguration = null)
    {
    }
}
