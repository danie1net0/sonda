<?php

declare(strict_types=1);

namespace CovProto;

use Closure;
use Composer\Autoload\ClassLoader;
use InvalidArgumentException;

use function str_starts_with;
use function substr;

/**
 * Runs before Composer's autoloader: a class whose file is inside the target directory is
 * loaded from its instrumented copy.
 */
final class InstrumentingAutoloader
{
    private readonly string $targetDirectory;

    private readonly InstrumentationCache $cache;

    /** @var array<string, array{0: string, 1: string}> file id => [source path, cache key] */
    private array $loadedFiles = [];

    public function __construct(
        private readonly ClassLoader $composer,
        string $targetDirectory,
        private readonly string $cacheDirectory,
        private readonly Instrumenter $instrumenter,
    ) {
        $realTargetDirectory = realpath($targetDirectory);

        if ($realTargetDirectory === false) {
            throw new InvalidArgumentException("O diretório {$targetDirectory} não existe.");
        }

        $this->targetDirectory = $realTargetDirectory . '/';
        $this->cache           = new InstrumentationCache($cacheDirectory);
    }

    public function register(): void
    {
        spl_autoload_register($this->load(...), prepend: true);
    }

    public function load(string $class): void
    {
        $file = $this->composer->findFile($class);

        if ($file === false) {
            return;
        }

        $path = realpath($file);

        if (!str_starts_with($path, $this->targetDirectory)) {
            return;
        }

        $fileId = substr(sha1($path), 0, 12);
        $key    = sha1($path . filemtime($path) . $this->instrumenter->fingerprint());

        if (!$this->cache->has($key)) {
            $this->cache->store($key, $this->instrumenter->instrument($path, $fileId));
        }

        $this->loadedFiles[$fileId] = [$path, $key];

        self::includeFile($this->cache->codePath($key));
    }

    public function run(): Run
    {
        return new Run($this->cacheDirectory, $this->loadedFiles, Hits::collected());
    }

    /** Includes outside any class scope, as Composer does. */
    private static function includeFile(string $file): void
    {
        Closure::bind(static function (string $file): void {
            include $file;
        }, null, null)($file);
    }
}
