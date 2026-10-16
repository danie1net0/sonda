<?php

declare(strict_types=1);

namespace Sonda;

use RuntimeException;

/**
 * What one test process collected: the instrumented files it loaded and the probes they hit.
 * With --parallel, each ParaTest worker saves its own run in the same directory.
 */
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
        $run = unserialize(Filesystem::read($path), ['allowed_classes' => [self::class]]);

        if (! $run instanceof self) {
            throw new RuntimeException("O arquivo {$path} não contém uma execução do sonda.");
        }

        return $run;
    }

    /** All the runs saved in a directory, merged into one. */
    public static function loadDirectory(string $directory): self
    {
        $files = glob($directory . '/run-*.ser') ?: [];

        if ($files === []) {
            throw new RuntimeException("O diretório {$directory} não tem execuções do sonda.");
        }

        return self::merge(array_map(self::load(...), $files));
    }

    /** @param non-empty-list<self> $runs every run of one test suite, sharing the cache */
    public static function merge(array $runs): self
    {
        $files = [];
        $hits = [];

        foreach ($runs as $run) {
            $files += $run->files;

            foreach ($run->hits as $fileId => $fileHits) {
                $hits[$fileId] = ($hits[$fileId] ?? []) + $fileHits;
            }
        }

        return new self($runs[0]->cacheDirectory, $files, $hits);
    }

    public function save(string $path): void
    {
        file_put_contents($path, serialize($this));
    }

    /** Saves under a name of its own, so the processes of a parallel run do not overwrite each other. */
    public function saveIn(string $directory): string
    {
        Filesystem::privateDirectory($directory);

        $path = $directory . '/run-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.ser';
        $this->save($path);

        return $path;
    }

    /** @return array<int, Probe> */
    public function probesOf(string $cacheKey): array
    {
        return $this->cache()->probes($cacheKey);
    }

    /**
     * The line map of a view, or null for a file instrumented as it is.
     *
     * @return array<int, int>|null compiled line => template line
     */
    public function lineMapOf(string $cacheKey): ?array
    {
        return $this->cache()->lineMap($cacheKey);
    }

    /** The plain compiled code of a view, the file the report analyses. */
    public function analysisPathOf(string $cacheKey): string
    {
        return $this->cache()->analysisPath($cacheKey);
    }

    private function cache(): InstrumentationCache
    {
        return new InstrumentationCache($this->cacheDirectory);
    }
}
