<?php

declare(strict_types=1);

namespace Sonda;

use RuntimeException;

/**
 * Instrumented copies of the source files and their probe maps, keyed by a hash of the
 * source path, its modification time and the instrumenter fingerprint.
 */
final readonly class InstrumentationCache
{
    public function __construct(private string $directory)
    {
    }

    public function codePath(string $key): string
    {
        return $this->directory . '/' . $key . '.php';
    }

    public function has(string $key): bool
    {
        return is_file($this->codePath($key));
    }

    /** The map is written before the code, so a cached file always has its map. */
    public function store(string $key, InstrumentationResult $result): void
    {
        Filesystem::privateDirectory($this->directory);

        $this->writeAtomically($this->mapPath($key), serialize($result->probes));
        $this->writeAtomically($this->codePath($key), $result->code);
    }

    /** @return array<int, Probe> */
    public function probes(string $key): array
    {
        $contents = unserialize(Filesystem::read($this->mapPath($key)), ['allowed_classes' => [Probe::class]]);
        $probes = [];

        foreach (is_array($contents) ? $contents : [null] as $id => $probe) {
            if (! is_int($id) || ! $probe instanceof Probe) {
                throw new RuntimeException("O mapa de sondas {$key} está corrompido no cache {$this->directory}.");
            }

            $probes[$id] = $probe;
        }

        return $probes;
    }

    private function mapPath(string $key): string
    {
        return $this->directory . '/' . $key . '.map';
    }

    /** Parallel test workers may write the same file: each writes its own temporary file. */
    private function writeAtomically(string $path, string $contents): void
    {
        $temporary = tempnam($this->directory, 'sonda');

        file_put_contents($temporary, $contents);
        rename($temporary, $path);
    }
}
