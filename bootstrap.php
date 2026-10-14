<?php

declare(strict_types=1);

// PHPUnit/Pest bootstrap, also with --parallel: --bootstrap <this file>
// Settings come from the environment (see Sonda\Settings::fromEnvironment).

use Sonda\Instrumenter;
use Sonda\InstrumentingAutoloader;
use Sonda\PhpUnit\CollectionWindow;
use Sonda\Settings;

require __DIR__ . '/autoload.php';

$settings   = Settings::fromEnvironment(getenv());
$autoloader = new InstrumentingAutoloader(
    require $settings->autoloadPath,
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
