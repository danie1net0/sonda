<?php

declare(strict_types=1);

namespace Sonda;

use Closure;

/**
 * Starts the process again with a prepend file: the only way to run code before the test runner
 * requires Composer's autoloader, which is when a helper of Composer's `files` and the classes
 * that the runner loads while it starts are still not loaded.
 */
final readonly class Restart
{
    /**
     * @param (Closure(string, list<string>): mixed)|null $replace   pcntl_exec(), where PHP has it: the same process, with its pipes
     * @param Closure(non-empty-list<string>): int        $spawn     runs the command on this process's streams and gives its status
     * @param Closure(int): mixed                         $terminate exit()
     */
    public function __construct(private ?Closure $replace, private Closure $spawn, private Closure $terminate)
    {
    }

    /** @param bool $canReplace whether PHP has pcntl_exec() */
    public static function native(bool $canReplace): self
    {
        return new self($canReplace ? pcntl_exec(...) : null, self::spawn(...), exit(...));
    }

    /**
     * The command that starts this process again with the prepend file, or null when it cannot be
     * started again: its code came from the standard input, or it already runs with that file.
     *
     * @param list<string> $arguments $_SERVER['argv']
     * @param string       $prepended the auto_prepend_file this process runs with
     *
     * @return non-empty-list<string>|null
     */
    public static function command(string $phpBinary, string $prepend, array $arguments, string $prepended): ?array
    {
        $script = $arguments[0] ?? null;

        if ($script === null || ! is_file($script) || $prepended === $prepend) {
            return null;
        }

        $command = [];
        $command[] = $phpBinary;
        $command[] = '-d';
        $command[] = 'auto_prepend_file=' . $prepend;
        array_push($command, ...$arguments);

        return $command;
    }

    /**
     * A PHP that always runs with the prepend file, written in the directory: the path for the
     * PHP_BINARY environment variable, where Symfony's PhpExecutableFinder (ParaTest, and so Pest
     * and Laravel in parallel) looks for the PHP of the workers. A worker cannot be started
     * again: by the time it loads the bootstrap it has read its first command.
     */
    public static function launcher(string $directory, string $phpBinary, string $prepend): string
    {
        $launcher = $directory . '/php-with-prepend';
        // Written apart and moved into place: parallel processes may write it at the same time.
        $draft = tempnam($directory, basename($launcher));

        file_put_contents($draft, "#!/bin/sh\nexec " . escapeshellarg($phpBinary) . ' -d ' . escapeshellarg('auto_prepend_file=' . $prepend) . " \"\$@\"\n");
        chmod($draft, 0o700);
        rename($draft, $launcher);

        return $launcher;
    }

    /**
     * Runs the command in place of this process, which ends with the status of the command.
     *
     * @param non-empty-list<string> $command
     */
    public function run(array $command): void
    {
        if ($this->replace instanceof Closure) {
            // Returns only when the command could not replace the process: then it runs as a child.
            ($this->replace)($command[0], array_slice($command, 1));
        }

        ($this->terminate)(($this->spawn)($command));
    }

    /**
     * Runs the command on the streams of this process and waits for it.
     *
     * @param non-empty-list<string> $command
     */
    public static function spawn(array $command): int
    {
        // The descriptors themselves, opened anew: the test runner may have put a filter on STDOUT,
        // and PHP refuses to hand a filtered stream to another process.
        $streams = [];
        $streams[] = ['file', 'php://fd/0', 'r'];
        $streams[] = ['file', 'php://fd/1', 'w'];
        $streams[] = ['file', 'php://fd/2', 'w'];
        $process = proc_open($command, $streams, $pipes);

        if ($process === false) {
            return 1;
        }

        return proc_close($process);
    }
}
