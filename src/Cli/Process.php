<?php

declare(strict_types=1);

namespace Sonda\Cli;

use RuntimeException;

use function implode;

final class Process
{
    /**
     * Starts a process, turning the warning PHP gives when it cannot (a missing binary, say)
     * into an exception.
     *
     * @param list<string>                                  $command
     * @param array<int, resource|array{0: string, 1: string}> $descriptors
     * @param array<int, resource>                          $pipes
     * @param array<string, string>|null                    $environment
     *
     * @return resource
     */
    public static function open(array $command, array $descriptors, array &$pipes, ?string $directory = null, ?array $environment = null)
    {
        $reason = '';
        set_error_handler(static function (int $number, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });

        try {
            $process = proc_open($command, $descriptors, $pipes, $directory, $environment);
        } finally {
            restore_error_handler();
        }

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start ' . implode(' ', $command) . ': ' . $reason);
        }

        return $process;
    }
}
