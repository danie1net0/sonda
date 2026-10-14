<?php

declare(strict_types=1);

namespace Sonda\Cli;

final readonly class SystemProcessRunner implements ProcessRunner
{
    /**
     * @param resource $input  what the command reads
     * @param resource $output where its output goes
     * @param resource $error  where its errors go
     */
    public function __construct(private mixed $input, private mixed $output, private mixed $error)
    {
    }

    public function run(array $command, array $environment, string $directory): int
    {
        $pipes = [];
        $process = Process::open($command, [0 => $this->input, 1 => $this->output, 2 => $this->error], $pipes, $directory, [...getenv(), ...$environment]);

        return proc_close($process);
    }
}
