<?php

declare(strict_types=1);

use Sonda\Project\Runner;

/** @param list<string> $files */
function projectWith(array $files): string
{
    $root = TemporaryDirectory::create();

    foreach ($files as $file) {
        if (! is_dir(dirname($root . '/' . $file))) {
            mkdir(dirname($root . '/' . $file), 0777, true);
        }

        file_put_contents($root . '/' . $file, '');
    }

    return $root;
}

it('runs a Laravel project through artisan, in parallel when ParaTest is installed', function (): void {
    expect(Runner::command(projectWith(['artisan', 'vendor/bin/pest', 'vendor/bin/paratest']), true))
        ->toBe([PHP_BINARY, 'artisan', 'test', '--parallel'])
        ->and(Runner::command(projectWith(['artisan', 'vendor/bin/phpunit']), true))
        ->toBe([PHP_BINARY, 'artisan', 'test'])
        ->and(Runner::command(projectWith(['artisan', 'vendor/bin/paratest']), false))
        ->toBe([PHP_BINARY, 'artisan', 'test']);
});

it('runs a Pest project through pest', function (): void {
    expect(Runner::command(projectWith(['vendor/bin/pest', 'vendor/bin/paratest']), true))
        ->toBe([PHP_BINARY, 'vendor/bin/pest', '--parallel'])
        ->and(Runner::command(projectWith(['vendor/bin/pest']), true))
        ->toBe([PHP_BINARY, 'vendor/bin/pest']);
});

it('runs a PHPUnit project through paratest or phpunit', function (): void {
    expect(Runner::command(projectWith(['vendor/bin/phpunit', 'vendor/bin/paratest']), true))
        ->toBe([PHP_BINARY, 'vendor/bin/paratest'])
        ->and(Runner::command(projectWith(['vendor/bin/phpunit', 'vendor/bin/paratest']), false))
        ->toBe([PHP_BINARY, 'vendor/bin/phpunit'])
        ->and(Runner::command(projectWith(['vendor/bin/phpunit']), true))
        ->toBe([PHP_BINARY, 'vendor/bin/phpunit']);
});

it('fails when no runner is installed', function (): void {
    $root = projectWith([]);

    expect(fn (): array => Runner::command($root, true))
        ->toThrow(RuntimeException::class, "Nenhum runner de testes em {$root}: esperava artisan, vendor/bin/pest ou vendor/bin/phpunit.");
});
