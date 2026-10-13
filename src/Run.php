<?php

declare(strict_types=1);

namespace CovProto;

use RuntimeException;

/** What one test run collected: the instrumented files it loaded and the probes they hit. */
final readonly class Run
{
    /**
     * @param array<string, array{0: string, 1: string}> $files file id => [source path, cache key]
     * @param array<string, array<int, int>>             $hits  file id => probe id => 1
     */
    public function __construct(
        public string $cacheDirectory,
        public array $files,
        public array $hits,
    ) {
    }

    public static function load(string $path): self
    {
        $run = is_file($path) ? unserialize(file_get_contents($path), ['allowed_classes' => [self::class]]) : null;

        if (!$run instanceof self) {
            throw new RuntimeException("O arquivo {$path} não contém uma execução do covproto.");
        }

        return $run;
    }

    public function save(string $path): void
    {
        file_put_contents($path, serialize($this));
    }

    /** @return array<int, Probe> */
    public function probesOf(string $cacheKey): array
    {
        return (new InstrumentationCache($this->cacheDirectory))->probes($cacheKey);
    }
}
