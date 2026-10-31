<?php

declare(strict_types=1);

use Sonda\Cli\Process;

it('starts a process and hands over its pipes', function (): void {
    $pipes = [];
    $process = Process::open([PHP_BINARY, '-r', 'echo getenv("SONDA_PROBE");'], [1 => ['pipe', 'w']], $pipes, null, ['SONDA_PROBE' => 'ok']);

    expect(stream_get_contents($pipes[1]))->toBe('ok')
        ->and(proc_close($process))->toBe(0);
});

it('fails instead of warning when the command cannot start', function (): void {
    $pipes = [];
    ob_start();

    try {
        expect(function () use (&$pipes): void {
            Process::open(['/missing/binary', 'x'], [1 => ['pipe', 'w']], $pipes);
        })->toThrow(RuntimeException::class, 'Could not start /missing/binary x: proc_open(): posix_spawn() failed: No such file or directory');
    } finally {
        $printed = ob_get_clean();
    }

    expect($printed)->toBe('');
});
