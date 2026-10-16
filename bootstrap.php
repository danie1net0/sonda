<?php

declare(strict_types=1);

// PHPUnit/Pest bootstrap, also with --parallel: --bootstrap <this file>
// Settings come from the environment (see Sonda\Settings::fromEnvironment).

use Composer\Autoload\ClassLoader;
use Sonda\{Instrumenter, InstrumentingAutoloader, Run, Settings};
use Sonda\Blade\Views;
use Sonda\PhpUnit\CollectionWindow;

require __DIR__ . '/autoload.php';

$settings = Settings::fromEnvironment(getenv());
$composer = require $settings->autoloadPath;

if (! $composer instanceof ClassLoader) {
    throw new RuntimeException("O autoload {$settings->autoloadPath} não devolveu o ClassLoader do Composer.");
}

$instrumenter = new Instrumenter($settings->strict, $settings->fast);
$autoloader = new InstrumentingAutoloader($composer, $settings->scope(), $settings->cacheDirectory, $instrumenter);
$autoloader->register();
$views = Views::configure($settings, $instrumenter);
CollectionWindow::open();

if ($settings->chainedBootstrap !== null) {
    require $settings->chainedBootstrap;
}

// Each process (ParaTest worker included) saves its own run in the output directory.
register_shutdown_function(static function () use ($autoloader, $settings, $views): void {
    $run = $autoloader->run();

    if ($views instanceof Views) {
        $run = new Run($run->cacheDirectory, $run->files + $views->files(), $run->hits);
    }

    $run->saveIn($settings->outputDirectory);
});
