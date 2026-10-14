<?php

declare(strict_types=1);

namespace Sonda;

use InvalidArgumentException;

use function str_starts_with;

/** Which files are instrumented and reported: the target directories, minus the excluded ones, narrowed to a list when given. */
final readonly class Scope
{
    /** @var list<string> real paths, with a trailing slash */
    private array $targetDirectories;

    /** @var list<string> real paths, with a trailing slash */
    private array $excludedDirectories;

    /** @var array<string, string> real paths, as keys and values */
    private array $onlyFiles;

    /**
     * @param list<string> $targetDirectories   must exist
     * @param list<string> $excludedDirectories a missing one excludes nothing
     * @param list<string> $onlyFiles           when not empty, only these files are in scope
     */
    public function __construct(array $targetDirectories, array $excludedDirectories = [], array $onlyFiles = [])
    {
        $targets = [];

        foreach ($targetDirectories as $directory) {
            $realDirectory = realpath($directory);

            if ($realDirectory === false) {
                throw new InvalidArgumentException("O diretório {$directory} não existe.");
            }

            $targets[] = $realDirectory . '/';
        }

        $excluded = [];

        foreach ($excludedDirectories as $directory) {
            $realDirectory = realpath($directory);

            if ($realDirectory !== false) {
                $excluded[] = $realDirectory . '/';
            }
        }

        $only = [];

        foreach ($onlyFiles as $file) {
            $realFile = realpath($file);

            if ($realFile !== false) {
                $only[$realFile] = $realFile;
            }
        }

        $this->targetDirectories = $targets;
        $this->excludedDirectories = $excluded;
        $this->onlyFiles = $only;
    }

    public function includes(string $realPath): bool
    {
        if ($this->onlyFiles !== [] && ! isset($this->onlyFiles[$realPath])) {
            return false;
        }

        if ($this->isUnder($realPath, $this->excludedDirectories)) {
            return false;
        }

        return $this->isUnder($realPath, $this->targetDirectories);
    }

    public function narrowsToFiles(): bool
    {
        return $this->onlyFiles !== [];
    }

    /** @return list<string> */
    public function targetDirectories(): array
    {
        return $this->targetDirectories;
    }

    /** @return list<string> */
    public function excludedDirectories(): array
    {
        return $this->excludedDirectories;
    }

    /** @param list<string> $directories */
    private function isUnder(string $path, array $directories): bool
    {
        return array_any($directories, fn (string $directory): bool => str_starts_with($path, $directory));
    }
}
