<?php

declare(strict_types=1);

namespace Sonda\Blade;

use Illuminate\Contracts\Container\Container;
use Illuminate\View\Compilers\BladeCompiler;
use Sonda\{Filesystem, InstrumentationCache, Instrumenter, Scope, Settings};
use Sonda\Report\SourceFiles;
use Throwable;

use function class_exists, dirname, max, str_starts_with, substr;

/**
 * The Blade views of a test process: compiled and instrumented once the Laravel application
 * exists, into a compiled-views directory of this process alone.
 *
 * Laravel reads `VIEW_COMPILED_PATH` from the environment when its view configuration loads;
 * the bootstrap points it at that directory before any test boots the application. The views
 * are then compiled with the application's own Blade compiler (custom directives included),
 * instrumented, and written where Laravel will include them, newer than their templates.
 */
final class Views
{
    private static ?self $instance = null;

    /** @var array<string, array{0: string, 1: string}> file id => [view path, cache key] */
    private array $files = [];

    private bool $prepared = false;

    private readonly ViewInstrumenter $viewInstrumenter;

    /**
     * @param list<string> $viewDirectories
     * @param resource     $errors          where a view left out is reported
     */
    public function __construct(
        private readonly Scope $scope,
        private readonly array $viewDirectories,
        private readonly InstrumentationCache $cache,
        private readonly string $compiledDirectory,
        private readonly Instrumenter $instrumenter,
        private readonly mixed $errors = STDERR,
    ) {
        $this->viewInstrumenter = new ViewInstrumenter($instrumenter);
    }

    /**
     * Points Laravel's compiled views at a directory of this process, through the environment
     * its configuration reads, and keeps the instance the test subscriber will prepare.
     */
    public static function configure(Settings $settings, Instrumenter $instrumenter): ?self
    {
        if ($settings->viewDirectories === []) {
            return self::$instance = null;
        }

        $directory = $settings->outputDirectory . '/views-' . getmypid();
        // Claimed before Laravel runs: a directory it created first would be open to other users.
        Filesystem::privateDirectory($directory);
        putenv('VIEW_COMPILED_PATH=' . $directory);
        $_ENV['VIEW_COMPILED_PATH'] = $directory;
        $_SERVER['VIEW_COMPILED_PATH'] = $directory;

        return self::$instance = new self($settings->scope(), $settings->viewDirectories, new InstrumentationCache($settings->cacheDirectory), $directory, $instrumenter);
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * Prepares the views with the application a container class holds, when that class is loaded:
     * without Laravel in the process there is nothing to prepare.
     *
     * @param class-string<\Illuminate\Container\Container> $containerClass
     */
    public function prepareFromApplication(string $containerClass): void
    {
        if (class_exists($containerClass, false)) {
            $this->prepare($containerClass::getInstance());
        }
    }

    /** Compiles and instruments the views once, with the Blade compiler of the application given. */
    public function prepare(Container $application): void
    {
        if ($this->prepared || ! $application->bound('blade.compiler')) {
            return;
        }

        $this->prepared = true;
        $compiler = $application->make('blade.compiler');

        if (! $compiler instanceof BladeCompiler) {
            return;
        }

        $compiledDirectory = dirname($compiler->getCompiledPath('/sonda'));

        // Compiled views outside this process's directory would reach the project's own cache, probes included.
        if (! str_starts_with($compiledDirectory . '/', $this->compiledDirectory . '/')) {
            fwrite($this->errors, "Sonda: the compiled views are not in {$this->compiledDirectory} (VIEW_COMPILED_PATH did not reach the configuration): views not measured.\n");

            return;
        }

        // Laravel's parallel testing compiles into a subdirectory per worker that it only creates on its first compile.
        Filesystem::ensureDirectory($compiledDirectory);

        foreach (SourceFiles::findAll($this->viewDirectories) as $view) {
            if (str_ends_with($view, '.blade.php') && $this->scope->includes($view)) {
                $this->prepareView($compiler, $view);
            }
        }
    }

    /** @return array<string, array{0: string, 1: string}> file id => [view path, cache key] */
    public function files(): array
    {
        return $this->files;
    }

    private function prepareView(BladeCompiler $compiler, string $view): void
    {
        $fileId = substr(sha1($view), 0, 12);
        $key = sha1($view . filemtime($view) . $this->instrumenter->fingerprint() . 'blade');
        $compiledPath = $compiler->getCompiledPath($view);

        if (! $this->cache->has($key)) {
            try {
                $this->cache->store($key, $this->viewInstrumenter->instrument($view, $compiledPath, $fileId, static fn (string $template): string => $compiler->compileString($template)));
            } catch (Throwable $throwable) {
                // A view that does not compile fails the tests that render it, with Laravel's own message.
                fwrite($this->errors, "Sonda: the view {$view} did not compile and is not measured: {$throwable->getMessage()}\n");

                return;
            }
        }

        copy($this->cache->codePath($key), $compiledPath);
        // Laravel compiles again, without probes, a view as new as its compiled file: a template saved this second stays older.
        touch($compiledPath, max(time(), filemtime($view) + 1));
        $this->files[$fileId] = [$view, $key];
    }
}
