<?php

declare(strict_types=1);

namespace CovProto;

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
     * COV_AUTOLOAD (autoload.php of the measured project), COV_TARGET (directory to instrument),
     * COV_CACHE (instrumented files) and COV_OUT (directory of the run files, one per process)
     * are required; COV_STRICT=1, COV_FAST=1 and COV_CHAIN (the project's own bootstrap) are
     * optional.
     *
     * @param array<string, string> $environment
     */
    public static function fromEnvironment(array $environment): self
    {
        $chainedBootstrap = $environment['COV_CHAIN'] ?? '';

        return new self(
            self::required($environment, 'COV_AUTOLOAD'),
            self::required($environment, 'COV_TARGET'),
            self::required($environment, 'COV_CACHE'),
            self::required($environment, 'COV_OUT'),
            ($environment['COV_STRICT'] ?? null) === '1',
            ($environment['COV_FAST'] ?? null) === '1',
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
