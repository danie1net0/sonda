<?php

declare(strict_types=1);

use CovProto\Settings;

function environment(array $overrides = []): array
{
    $environment                 = [];
    $environment['COV_AUTOLOAD'] = 'vendor/autoload.php';
    $environment['COV_TARGET']   = 'app';
    $environment['COV_CACHE']    = '/tmp/cache';
    $environment['COV_OUT']      = '/tmp/run.ser';

    return array_merge($environment, $overrides);
}

it('reads the required settings and defaults the optional ones', function (): void {
    expect(Settings::fromEnvironment(environment()))
        ->autoloadPath->toBe('vendor/autoload.php')
        ->targetDirectory->toBe('app')
        ->cacheDirectory->toBe('/tmp/cache')
        ->outputFile->toBe('/tmp/run.ser')
        ->strict->toBeFalse()
        ->fast->toBeFalse()
        ->chainedBootstrap->toBeNull();
});

it('reads the optional settings', function (): void {
    $settings = Settings::fromEnvironment(environment(['COV_STRICT' => '1', 'COV_FAST' => '1', 'COV_CHAIN' => 'tests/bootstrap.php']));

    expect($settings)
        ->strict->toBeTrue()
        ->fast->toBeTrue()
        ->chainedBootstrap->toBe('tests/bootstrap.php');
});

it('turns on flags only with the value 1', function (): void {
    expect(Settings::fromEnvironment(environment(['COV_STRICT' => 'true', 'COV_FAST' => '0'])))
        ->strict->toBeFalse()
        ->fast->toBeFalse();
});

it('treats an empty chained bootstrap as none', function (): void {
    expect(Settings::fromEnvironment(environment(['COV_CHAIN' => ''])))->chainedBootstrap->toBeNull();
});

it('requires each mandatory setting', function (string $name): void {
    $environment = environment();
    unset($environment[$name]);

    expect(fn (): Settings => Settings::fromEnvironment($environment))
        ->toThrow(InvalidArgumentException::class, "A variável de ambiente {$name} é obrigatória.");
})->with(['COV_AUTOLOAD', 'COV_TARGET', 'COV_CACHE', 'COV_OUT']);

it('rejects an empty mandatory setting', function (): void {
    expect(fn (): Settings => Settings::fromEnvironment(environment(['COV_TARGET' => ''])))
        ->toThrow(InvalidArgumentException::class, 'A variável de ambiente COV_TARGET é obrigatória.');
});
