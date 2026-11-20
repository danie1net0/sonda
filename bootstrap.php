<?php

declare(strict_types=1);

// PHPUnit/Pest bootstrap, also with --parallel: --bootstrap <this file>
// Settings come from the environment (see Sonda\Settings::fromEnvironment).

use Composer\Autoload\ClassLoader;
use Sonda\{ComposerFiles, IncludeWrapper, Instrumenter, InstrumentingAutoloader, Restart, Run, Settings};
use Sonda\Blade\Views;
use Sonda\PhpUnit\CollectionWindow;

require __DIR__ . '/autoload.php';

$settings = Settings::fromEnvironment(getenv());
// Where this bootstrap is the first to require Composer's autoloader, the files of the scope in
// its `files` load instrumented as Composer reaches them.
ComposerFiles::intercept($settings);
$composer = require $settings->autoloadPath;

if (! $composer instanceof ClassLoader) {
    throw new RuntimeException("O autoload {$settings->autoloadPath} não devolveu o ClassLoader do Composer.");
}

$autoloader = InstrumentingAutoloader::shared($settings);
// The test runner requires Composer's autoloader before it reaches this bootstrap: a helper of
// Composer's `files` and the classes it loaded while starting are there without probes. Only a
// process started again, with the prepend file, loads them instrumented.
$uninstrumented = $autoloader->uninstrumented(get_included_files());
$prepend = __DIR__ . '/prepend.php';
$prepended = (string) ini_get('auto_prepend_file');
// A parallel worker (TEST_TOKEN) has read its first command by now: started again, it would wait for it forever.
$restartable = $uninstrumented !== [] && getenv('TEST_TOKEN') === false;
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$restart = $restartable ? Restart::command(PHP_BINARY, $prepend, $arguments, $prepended) : null;

if ($restart !== null) {
    // The auto_prepend_file of the user's own configuration, which the prepend file requires in turn.
    putenv('SONDA_PREPEND_CHAIN=' . $prepended);
    // The parallel workers that this process starts come with the prepend file from their first line.
    putenv('PHP_BINARY=' . Restart::launcher($settings->cacheDirectory, PHP_BINARY, $prepend));
    Restart::native(function_exists('pcntl_exec'))->run($restart);
}

if ($uninstrumented !== []) {
    fwrite(STDERR, "Sonda: loaded before the instrumentation, in a process that cannot be started again, and not measured in it:\n  " . implode("\n  ", $uninstrumented) . "\n");
}

$autoloader->register($composer);

$views = Views::configure($settings, new Instrumenter($settings->strict, $settings->fast));

// What the autoloader never sees, a file of the scope included by its path, comes instrumented
// too, when the scope has such files, in the processes that run tests. The parallel orchestrator
// follows the output files of its workers as they grow, which a stream wrapper would see end at
// the first end of file.
$runsTests = CollectionWindow::open();

if ($runsTests && $settings->includes) {
    IncludeWrapper::register($autoloader->copyFor(...));
}

if ($settings->chainedBootstrap !== null) {
    require $settings->chainedBootstrap;
}

// Each process (ParaTest worker included) saves its own run in the output directory.
register_shutdown_function(static function () use ($autoloader, $settings, $views): void {
    $run = $autoloader->run();

    if ($views instanceof Views) {
        $run = new Run($run->cacheDirectory, $run->files + $views->files(), $run->hits, $run->tests, $run->problems);
    }

    $run->saveIn($settings->outputDirectory);
});
