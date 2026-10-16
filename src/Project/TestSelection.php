<?php

declare(strict_types=1);

namespace Sonda\Project;

use function str_starts_with;

/** The test paths among the arguments handed to the runner, apart from its options and its configuration file. */
final readonly class TestSelection
{
    /**
     * @param list<string> $options the arguments that are not paths, minus `-c`/`--configuration` and its file
     * @param list<string> $paths   test files and directories, as given
     */
    public function __construct(public array $options, public array $paths)
    {
    }

    /** @param list<string> $runnerArguments */
    public static function of(array $runnerArguments): self
    {
        $options = [];
        $paths = [];
        $skipNext = false;

        foreach ($runnerArguments as $argument) {
            if ($skipNext) {
                $skipNext = false;

                continue;
            }

            if ($argument === '-c' || $argument === '--configuration') {
                $skipNext = true;
            } elseif (str_starts_with($argument, '--configuration=')) {
                continue;
            } elseif (str_starts_with($argument, '-')) {
                $options[] = $argument;
            } else {
                $paths[] = $argument;
            }
        }

        return new self($options, $paths);
    }
}
