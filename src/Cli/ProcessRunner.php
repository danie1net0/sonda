<?php

declare(strict_types=1);

namespace Sonda\Cli;

/** Runs a command with the terminal attached, so its output streams as it goes. */
interface ProcessRunner
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $environment added to the current one
     *
     * @return int the exit code
     */
    public function run(array $command, array $environment, string $directory): int;
}
