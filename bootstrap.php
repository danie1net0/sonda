<?php

declare(strict_types=1);

// PHPUnit/Pest bootstrap, also with --parallel: --bootstrap <this file>
// Settings come from the environment (see Sonda\Settings::fromEnvironment).

use Composer\Autoload\ClassLoader;
use Sonda\{ComposerFiles, IncludeWrapper, Instrumenter, InstrumentingAutoloader, Run, Settings};
use Sonda\Blade\Views;
use Sonda\PhpUnit\CollectionWindow;

require __DIR__ . '/autoload.php';

$settings = Settings::fromEnvironment(getenv());
// Composer's `files` in scope are claimed first, so Composer skips them; they load instrumented below.
$claimedFiles = ComposerFiles::claim($settings->autoloadPath, $settings->scope());
$composer = require $settings->autoloadPath;

if (! $composer instanceof ClassLoader) {
    throw new RuntimeException("O autoload {$settings->autoloadPath} não devolveu o ClassLoader do Composer.");
}

$instrumenter = new Instrumenter($settings->strict, $settings->fast);
$autoloader = new InstrumentingAutoloader($composer, $settings->scope(), $settings->cacheDirectory, $instrumenter);
$autoloader->register();

foreach ($claimedFiles as $claimedFile) {
    $autoloader->loadFile($claimedFile);
}

$views = Views::configure($settings, $instrumenter);

// What the autoloader never sees, a file of the scope included by its path, comes instrumented
// too, when the scope has such files, in the processes that run tests. The parallel orchestrator
// follows the output files of its workers as they grow, which a stream wrapper would see end at
// the first end of file. Every operation the wrapper hands PHP costs memory PHP frees only at the
// end: the stats of the dependencies and of the code under test, asked for again and again, are kept.
$runsTests = CollectionWindow::open();

if ($runsTests && $settings->includes) {
    IncludeWrapper::register($autoloader->copyFor(...), [dirname($settings->autoloadPath), ...$settings->targetDirectories]);
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
