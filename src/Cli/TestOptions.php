<?php

declare(strict_types=1);

namespace Sonda\Cli;

use InvalidArgumentException;

use function is_numeric, str_replace, str_starts_with, substr;

/** The options of `sonda test`; whatever it does not know goes to the test runner. */
final readonly class TestOptions
{
    /**
     * @param non-empty-string|null $changed
     * @param list<string>          $files
     * @param list<string>          $views
     * @param non-empty-string|null $html
     * @param non-empty-string|null $clover
     * @param non-empty-string|null $cov
     * @param non-empty-string|null $cobertura
     * @param non-empty-string|null $codeQuality
     * @param non-empty-string|null $map
     * @param list<string>          $runnerArguments
     */
    public function __construct(
        public ?string $changed = null,
        public array $files = [],
        public bool $strict = true,
        public bool $fast = true,
        public bool $parallel = true,
        public bool $blade = true,
        public array $views = [],
        public bool $text = false,
        public bool $coveredBy = false,
        public bool $affected = false,
        public ?bool $includes = null,
        public ?float $minLines = null,
        public ?float $minBranches = null,
        public ?string $configuration = null,
        public ?string $html = null,
        public ?string $clover = null,
        public ?string $cov = null,
        public ?string $cobertura = null,
        public ?string $codeQuality = null,
        public ?string $map = null,
        public array $runnerArguments = [],
    ) {
    }

    /** @param list<string> $arguments */
    public static function parse(array $arguments): self
    {
        $changed = null;
        $files = [];
        $strict = true;
        $fast = true;
        $parallel = true;
        $blade = true;
        $views = [];
        $text = false;
        $coveredBy = false;
        $affected = false;
        $includes = null;
        $minLines = null;
        $minBranches = null;
        $configuration = null;
        $html = null;
        $clover = null;
        $cov = null;
        $cobertura = null;
        $codeQuality = null;
        $map = null;
        $runnerArguments = [];
        $count = count($arguments);

        for ($index = 0; $index < $count; $index++) {
            $argument = $arguments[$index];

            if ($argument === '--') {
                $runnerArguments = [...$runnerArguments, ...array_slice($arguments, $index + 1)];

                break;
            }

            if ($argument === '--no-strict') {
                $strict = false;
            } elseif ($argument === '--no-fast') {
                $fast = false;
            } elseif ($argument === '--no-parallel') {
                $parallel = false;
            } elseif ($argument === '--no-blade') {
                $blade = false;
            } elseif ($argument === '--text') {
                $text = true;
            } elseif ($argument === '--covered-by') {
                $coveredBy = true;
            } elseif ($argument === '--affected') {
                $affected = true;
            } elseif ($argument === '--includes' || $argument === '--no-includes') {
                $includes = $argument === '--includes';
            } elseif (self::valueOf($argument, '--min-lines') !== null) {
                $minLines = self::percentage(self::valueOf($argument, '--min-lines'), '--min-lines');
            } elseif (self::valueOf($argument, '--min-branches') !== null) {
                $minBranches = self::percentage(self::valueOf($argument, '--min-branches'), '--min-branches');
            } elseif (self::valueOf($argument, '--changed') !== null) {
                $changed = self::valueOf($argument, '--changed');
            } elseif (self::valueOf($argument, '--files') !== null) {
                $files = [...$files, ...array_filter(explode(',', self::valueOf($argument, '--files')))];
            } elseif (self::valueOf($argument, '--views') !== null) {
                $views = [...$views, ...array_filter(explode(',', self::valueOf($argument, '--views')))];
            } elseif (self::valueOf($argument, '--html') !== null) {
                $html = self::valueOf($argument, '--html');
            } elseif (self::valueOf($argument, '--clover') !== null) {
                $clover = self::valueOf($argument, '--clover');
            } elseif (self::valueOf($argument, '--cov') !== null) {
                $cov = self::valueOf($argument, '--cov');
            } elseif (self::valueOf($argument, '--cobertura') !== null) {
                $cobertura = self::valueOf($argument, '--cobertura');
            } elseif (self::valueOf($argument, '--code-quality') !== null) {
                $codeQuality = self::valueOf($argument, '--code-quality');
            } elseif (self::valueOf($argument, '--map') !== null) {
                $map = self::valueOf($argument, '--map');
            } elseif ($argument === '-c' || $argument === '--configuration') {
                $configuration = $arguments[$index + 1] ?? throw new InvalidArgumentException("A opção {$argument} precisa de um arquivo.");
                $runnerArguments[] = $argument;
                $runnerArguments[] = $configuration;
                $index++;
            } elseif (self::valueOf($argument, '--configuration') !== null) {
                $configuration = self::valueOf($argument, '--configuration');
                $runnerArguments[] = $argument;
            } else {
                $runnerArguments[] = $argument;
            }
        }

        if ($affected && $changed === null && $files === []) {
            throw new InvalidArgumentException('A opção --affected precisa de --changed ou --files: são eles que dizem o que mudou.');
        }

        return new self($changed, $files, $strict, $fast, $parallel, $blade, $views, $text, $coveredBy, $affected, $includes, $minLines, $minBranches, $configuration, $html, $clover, $cov, $cobertura, $codeQuality, $map, $runnerArguments);
    }

    /** A percentage from 0 to 100, with comma or point. */
    private static function percentage(string $value, string $option): float
    {
        $number = str_replace(',', '.', $value);

        if (! is_numeric($number) || (float) $number < 0 || (float) $number > 100) {
            throw new InvalidArgumentException("A opção {$option} precisa de um número de 0 a 100.");
        }

        return (float) $number;
    }

    /**
     * The value of `--name=value`, or null when the argument is another option.
     *
     * @return non-empty-string|null
     */
    private static function valueOf(string $argument, string $name): ?string
    {
        if (! str_starts_with($argument, $name . '=')) {
            return null;
        }

        $value = substr($argument, strlen($name) + 1);

        if ($value === '') {
            throw new InvalidArgumentException("A opção {$name} precisa de um valor.");
        }

        return $value;
    }
}
