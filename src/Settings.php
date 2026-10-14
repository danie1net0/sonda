<?php

declare(strict_types=1);

namespace Sonda;

use InvalidArgumentException;

/** What the bootstrap reads from the environment. */
final readonly class Settings
{
    public function __construct(
        public string $autoloadPath,
        public string $targetDirectory,
        public string $cacheDirectory,
        public string $outputDirectory,
        public bool $strict,
        public bool $fast,
        public ?string $chainedBootstrap,
    ) {
    }

    /**
     * SONDA_AUTOLOAD (autoload.php of the measured project), SONDA_TARGET (directory to instrument),
     * SONDA_CACHE (instrumented files) and SONDA_OUT (directory of the run files, one per process)
     * are required; SONDA_STRICT=1, SONDA_FAST=1 and SONDA_CHAIN (the project's own bootstrap) are
     * optional.
     *
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(array $environment): self
    {
        $chainedBootstrap = $environment['SONDA_CHAIN'] ?? '';

        return new self(
            self::required($environment, 'SONDA_AUTOLOAD'),
            self::required($environment, 'SONDA_TARGET'),
            self::required($environment, 'SONDA_CACHE'),
            self::required($environment, 'SONDA_OUT'),
            ($environment['SONDA_STRICT'] ?? null) === '1',
            ($environment['SONDA_FAST'] ?? null) === '1',
            $chainedBootstrap !== '' ? $chainedBootstrap : null,
        );
    }

    /** @param array<string, string> $environment */
    private static function required(array $environment, string $name): string
    {
        $value = $environment[$name] ?? '';

        if ($value === '') {
            throw new InvalidArgumentException("A variável de ambiente {$name} é obrigatória.");
        }

        return $value;
    }
}
