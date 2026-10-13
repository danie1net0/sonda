<?php

declare(strict_types=1);

// PHPUnit/Pest bootstrap: --bootstrap <this file> --extension 'CovProto\PhpUnit\Extension'
// Settings come from the environment (see CovProto\Settings::fromEnvironment).

use CovProto\Instrumenter;
use CovProto\InstrumentingAutoloader;
use CovProto\Settings;

require __DIR__ . '/autoload.php';

$settings   = Settings::fromEnvironment(getenv());
$autoloader = new InstrumentingAutoloader(
    require $settings->autoloadPath,
    $settings->targetDirectory,
    $settings->cacheDirectory,
    new Instrumenter($settings->strict, $settings->fast),
);
$autoloader->register();

if ($settings->chainedBootstrap !== null) {
    require $settings->chainedBootstrap;
}

register_shutdown_function(static function () use ($autoloader, $settings): void {
    $autoloader->run()->save($settings->outputFile);
});
