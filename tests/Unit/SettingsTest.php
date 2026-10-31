<?php

declare(strict_types=1);

use Sonda\{Scope, Settings};

/**
 * @param array<string, string> $overrides
 *
 * @return array<string, string>
 */
function environment(array $overrides = []): array
{
    $environment = [];
    $environment['SONDA_AUTOLOAD'] = 'vendor/autoload.php';
    $environment['SONDA_TARGET'] = 'app';
    $environment['SONDA_CACHE'] = '/tmp/cache';
    $environment['SONDA_OUT'] = '/tmp/runs';

    return array_merge($environment, $overrides);
}

it('reads the required settings and defaults the optional ones', function (): void {
    expect(Settings::fromEnvironment(environment()))
        ->autoloadPath->toBe('vendor/autoload.php')
        ->targetDirectories->toBe(['app'])
        ->excludedDirectories->toBe([])
        ->onlyFiles->toBe([])
        ->cacheDirectory->toBe('/tmp/cache')
        ->outputDirectory->toBe('/tmp/runs')
        ->strict->toBeFalse()
        ->fast->toBeFalse()
        ->chainedBootstrap->toBeNull()
        ->viewDirectories->toBe([]);
});

it('reads the optional settings', function (): void {
    $settings = Settings::fromEnvironment(environment([
        'SONDA_TARGET' => 'app' . PATH_SEPARATOR . 'src',
        'SONDA_EXCLUDE' => 'app/Providers' . PATH_SEPARATOR . PATH_SEPARATOR . 'app/Console',
        'SONDA_ONLY' => 'app/Order.php',
        'SONDA_STRICT' => '1',
        'SONDA_FAST' => '1',
        'SONDA_CHAIN' => 'tests/bootstrap.php',
        'SONDA_VIEWS' => 'resources/views' . PATH_SEPARATOR . 'other/views',
        'SONDA_INCLUDES' => '1',
    ]));

    expect($settings)
        ->targetDirectories->toBe(['app', 'src'])
        ->excludedDirectories->toBe(['app/Providers', 'app/Console'])
        ->onlyFiles->toBe(['app/Order.php'])
        ->strict->toBeTrue()
        ->fast->toBeTrue()
        ->chainedBootstrap->toBe('tests/bootstrap.php')
        ->viewDirectories->toBe(['resources/views', 'other/views'])
        ->includes->toBeTrue();
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
        ->toThrow(InvalidArgumentException::class, "The environment variable {$name} is required.");
})->with(['SONDA_AUTOLOAD', 'SONDA_TARGET', 'SONDA_CACHE', 'SONDA_OUT']);

it('rejects an empty mandatory setting', function (): void {
    expect(fn (): Settings => Settings::fromEnvironment(environment(['SONDA_TARGET' => ''])))
        ->toThrow(InvalidArgumentException::class, 'The environment variable SONDA_TARGET is required.');
});

it('builds the scope of the instrumentation', function (): void {
    $directory = TemporaryDirectory::create();
    mkdir($directory . '/app/Providers', 0777, true);
    file_put_contents($directory . '/app/Order.php', '<?php');
    $settings = Settings::fromEnvironment(environment([
        'SONDA_TARGET' => $directory . '/app',
        'SONDA_EXCLUDE' => $directory . '/app/Providers',
        'SONDA_ONLY' => $directory . '/app/Order.php',
    ]));

    expect($settings->scope())->toEqual(new Scope([$directory . '/app'], [$directory . '/app/Providers'], [$directory . '/app/Order.php']));
});

it('leaves the files included by their path alone unless told with the value 1', function (): void {
    expect(Settings::fromEnvironment(environment([]))->includes)->toBeFalse()
        ->and(Settings::fromEnvironment(environment(['SONDA_INCLUDES' => 'true']))->includes)->toBeFalse();
});
