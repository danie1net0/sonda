<?php

declare(strict_types=1);

namespace Sonda\Cli;

use InvalidArgumentException;

use function str_starts_with, substr;

/** The options of `sonda compare`: the output directory, the knobs of the comparison script, and the rest for the runner. */
final readonly class CompareOptions
{
    /** @param list<string> $runnerArguments */
    public function __construct(
        public string $outputDirectory,
        public ?int $processes = null,
        public ?int $batch = null,
        public ?string $xdebug = null,
        public array $runnerArguments = [],
    ) {
    }

    /** @param list<string> $arguments */
    public static function parse(array $arguments): self
    {
        $outputDirectory = null;
        $processes = null;
        $batch = null;
        $xdebug = null;
        $runnerArguments = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--processes=')) {
                $processes = self::count($argument, '--processes');
            } elseif (str_starts_with($argument, '--batch=')) {
                $batch = self::count($argument, '--batch');
            } elseif (str_starts_with($argument, '--xdebug=')) {
                $xdebug = substr($argument, strlen('--xdebug='));
            } elseif ($outputDirectory === null && ! str_starts_with($argument, '-')) {
                $outputDirectory = $argument;
            } else {
                $runnerArguments[] = $argument;
            }
        }

        if ($outputDirectory === null) {
            throw new InvalidArgumentException('The compare command needs the output directory: sonda compare <output>.');
        }

        return new self($outputDirectory, $processes, $batch, $xdebug, $runnerArguments);
    }

    private static function count(string $argument, string $option): int
    {
        $value = substr($argument, strlen($option) + 1);

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException("The option {$option} needs an integer of 1 or more.");
        }

        return (int) $value;
    }
}
