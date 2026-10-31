<?php

declare(strict_types=1);

namespace Sonda;

use InvalidArgumentException;

/** What the bootstrap reads from the environment. */
final readonly class Settings
{
    /**
     * @param list<string> $targetDirectories
     * @param list<string> $excludedDirectories
     * @param list<string> $onlyFiles
     * @param list<string> $viewDirectories
     */
    public function __construct(
        public string $autoloadPath,
        public array $targetDirectories,
        public array $excludedDirectories,
        public array $onlyFiles,
        public string $cacheDirectory,
        public string $outputDirectory,
        public bool $strict,
        public bool $fast,
        public ?string $chainedBootstrap,
        public array $viewDirectories = [],
        public bool $includes = false,
    ) {
    }

    /**
     * Required: SONDA_AUTOLOAD (autoload.php of the measured project), SONDA_TARGET (directories
     * to instrument), SONDA_CACHE (instrumented files) and SONDA_OUT (directory of the run files,
     * one per process). Optional: SONDA_EXCLUDE (directories left out), SONDA_ONLY (the only files
     * measured), SONDA_STRICT=1, SONDA_FAST=1, SONDA_CHAIN (the project's own bootstrap),
     * SONDA_VIEWS (directories of Blade views to measure) and SONDA_INCLUDES=1 (instrument the
     * files of the scope included by their path too). Lists are separated by PATH_SEPARATOR.
     *
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(array $environment): self
    {
        $chainedBootstrap = $environment['SONDA_CHAIN'] ?? '';

        return new self(
            self::required($environment, 'SONDA_AUTOLOAD'),
            self::list(self::required($environment, 'SONDA_TARGET')),
            self::list($environment['SONDA_EXCLUDE'] ?? ''),
            self::list($environment['SONDA_ONLY'] ?? ''),
            self::required($environment, 'SONDA_CACHE'),
            self::required($environment, 'SONDA_OUT'),
            ($environment['SONDA_STRICT'] ?? null) === '1',
            ($environment['SONDA_FAST'] ?? null) === '1',
            $chainedBootstrap !== '' ? $chainedBootstrap : null,
            self::list($environment['SONDA_VIEWS'] ?? ''),
            ($environment['SONDA_INCLUDES'] ?? null) === '1',
        );
    }

    public function scope(): Scope
    {
        return new Scope($this->targetDirectories, $this->excludedDirectories, $this->onlyFiles);
    }

    /** @param array<string, string> $environment */
    private static function required(array $environment, string $name): string
    {
        $value = $environment[$name] ?? '';

        if ($value === '') {
            throw new InvalidArgumentException("The environment variable {$name} is required.");
        }

        return $value;
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(explode(PATH_SEPARATOR, $value), static fn (string $item): bool => $item !== ''));
    }
}
