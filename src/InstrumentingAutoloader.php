<?php

declare(strict_types=1);

namespace Sonda;

use Closure;
use Composer\Autoload\ClassLoader;

use function substr;

/**
 * Runs before Composer's autoloader: a class whose file is in scope is loaded from its
 * instrumented copy.
 */
final class InstrumentingAutoloader
{
    private readonly InstrumentationCache $cache;

    /** @var array<string, array{0: string, 1: string}> file id => [source path, cache key] */
    private array $loadedFiles = [];

    public function __construct(
        private readonly ClassLoader $composer,
        private readonly Scope $scope,
        private readonly string $cacheDirectory,
        private readonly Instrumenter $instrumenter,
    ) {
        $this->cache = new InstrumentationCache($cacheDirectory);
    }

    /** The cache is where the included code comes from: it must be the current user's alone. */
    public function register(): void
    {
        Filesystem::privateDirectory($this->cacheDirectory);

        spl_autoload_register($this->load(...), prepend: true);
    }

    public function load(string $class): void
    {
        $file = $this->composer->findFile($class);

        if ($file === false) {
            return;
        }

        $path = realpath($file);

        // A classmap can still list a deleted file: Composer reports it, as without sonda.
        if ($path === false || ! $this->scope->includes($path)) {
            return;
        }

        $this->loadFile($path);
    }

    /** Loads a file of the scope from its instrumented copy: a class file, or one of Composer's `files`. */
    public function loadFile(string $path): void
    {
        $this->includeFile($this->instrumented($path));
    }

    /**
     * The instrumented copy of a PHP file of the scope that code includes by its path, outside
     * the autoloader; null for any other file, which is included as it is. Blade templates are
     * never included: their compiled code is, and the views are instrumented apart.
     */
    public function copyFor(string $path): ?string
    {
        $realPath = realpath($path);

        if ($realPath === false || ! str_ends_with($realPath, '.php') || str_ends_with($realPath, '.blade.php') || ! $this->scope->includes($realPath)) {
            return null;
        }

        return $this->instrumented($realPath);
    }

    public function run(): Run
    {
        return new Run($this->cacheDirectory, $this->loadedFiles, Hits::collected(), Hits::$tests, array_keys(Hits::$problems));
    }

    /** The path of the instrumented copy of a file of the scope, which the run then counts as loaded. */
    private function instrumented(string $path): string
    {
        $fileId = substr(sha1($path), 0, 12);
        $key = sha1($path . filemtime($path) . $this->instrumenter->fingerprint());

        if (! $this->cache->has($key)) {
            $this->cache->store($key, $this->instrumenter->instrument($path, $fileId));
        }

        $this->loadedFiles[$fileId] = [$path, $key];

        return $this->cache->codePath($key);
    }

    /** Includes outside any class scope, as Composer does. */
    private function includeFile(string $file): void
    {
        Closure::bind(static function (string $file): void {
            include $file;
        }, null, null)($file);
    }
}
