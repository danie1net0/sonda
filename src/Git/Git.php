<?php

declare(strict_types=1);

namespace Sonda\Git;

use RuntimeException;
use Sonda\Cli\Process;

use function implode, trim;

final class Git
{
    /**
     * Runs a git command in a repository and returns what it printed.
     *
     * @param list<string> $arguments
     */
    public static function run(string $repository, array $arguments): string
    {
        $pipes = [];
        $process = Process::open(['git', '-C', $repository, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) ?: '';
        $error = stream_get_contents($pipes[2]) ?: '';

        if (proc_close($process) !== 0) {
            throw new RuntimeException('git ' . implode(' ', $arguments) . ' falhou: ' . trim($error));
        }

        return $output;
    }
}
