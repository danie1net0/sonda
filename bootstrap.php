<?php

declare(strict_types=1);

// PHPUnit/Pest bootstrap, also with --parallel: --bootstrap <this file>
// Settings come from the environment (see Sonda\Settings::fromEnvironment).

use Composer\Autoload\ClassLoader;
use Sonda\{Instrumenter, InstrumentingAutoloader, Settings};
use Sonda\PhpUnit\CollectionWindow;

require __DIR__ . '/autoload.php';

$settings = Settings::fromEnvironment(getenv());
$composer = require $settings->autoloadPath;

if (! $composer instanceof ClassLoader) {
    throw new RuntimeException("O autoload {$settings->autoloadPath} não devolveu o ClassLoader do Composer.");
}

$autoloader = new InstrumentingAutoloader(
    $composer,
    $settings->targetDirectory,
    $settings->cacheDirectory,
    new Instrumenter($settings->strict, $settings->fast),
);
$autoloader->register();
CollectionWindow::open();

if ($settings->chainedBootstrap !== null) {
    require $settings->chainedBootstrap;
}

// Each process (ParaTest worker included) saves its own run in the output directory.
register_shutdown_function(static function () use ($autoloader, $settings): void {
    $autoloader->run()->saveIn($settings->outputDirectory);
});
