<?php

declare(strict_types=1);

use Sonda\Settings;

function environment(array $overrides = []): array
{
    $environment                 = [];
    $environment['SONDA_AUTOLOAD'] = 'vendor/autoload.php';
    $environment['SONDA_TARGET']   = 'app';
    $environment['SONDA_CACHE']    = '/tmp/cache';
    $environment['SONDA_OUT']      = '/tmp/runs';

    return array_merge($environment, $overrides);
}

it('reads the required settings and defaults the optional ones', function (): void {
    expect(Settings::fromEnvironment(environment()))
        ->autoloadPath->toBe('vendor/autoload.php')
        ->targetDirectory->toBe('app')
        ->cacheDirectory->toBe('/tmp/cache')
        ->outputDirectory->toBe('/tmp/runs')
        ->strict->toBeFalse()
        ->fast->toBeFalse()
        ->chainedBootstrap->toBeNull();
});

it('reads the optional settings', function (): void {
    $settings = Settings::fromEnvironment(environment(['SONDA_STRICT' => '1', 'SONDA_FAST' => '1', 'SONDA_CHAIN' => 'tests/bootstrap.php']));

    expect($settings)
        ->strict->toBeTrue()
        ->fast->toBeTrue()
        ->chainedBootstrap->toBe('tests/bootstrap.php');
});

it('turns on flags only with the value 1', function (): void {
    expect(Settings::fromEnvironment(environment(['SONDA_STRICT' => 'true', 'SONDA_FAST' => '0'])))
        ->strict->toBeFalse()
        ->fast->toBeFalse();
});

it('treats an empty chained bootstrap as none', function (): void {
    expect(Settings::fromEnvironment(environment(['SONDA_CHAIN' => ''])))->chainedBootstrap->toBeNull();
});

it('requires each mandatory setting', function (string $name): void {
    $environment = environment();
    unset($environment[$name]);

    expect(fn (): Settings => Settings::fromEnvironment($environment))
        ->toThrow(InvalidArgumentException::class, "A variável de ambiente {$name} é obrigatória.");
})->with(['SONDA_AUTOLOAD', 'SONDA_TARGET', 'SONDA_CACHE', 'SONDA_OUT']);

it('rejects an empty mandatory setting', function (): void {
    expect(fn (): Settings => Settings::fromEnvironment(environment(['SONDA_TARGET' => ''])))
        ->toThrow(InvalidArgumentException::class, 'A variável de ambiente SONDA_TARGET é obrigatória.');
});
