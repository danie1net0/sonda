<?php

declare(strict_types=1);

use Sonda\Cli\CompareOptions;

it('reads the output directory, its own options and leaves the rest to the runner', function (): void {
    expect(CompareOptions::parse(['--processes=6', 'build/compare', '--batch=80', '--xdebug=/opt/xdebug.so', '--filter=Order', 'tests/Feature', '-v']))
        ->toEqual(new CompareOptions('build/compare', 6, 80, '/opt/xdebug.so', ['--filter=Order', 'tests/Feature', '-v']));
});

it('leaves the knobs unset when not given, and takes the first argument that is not an option as the output', function (): void {
    expect(CompareOptions::parse(['out']))->toEqual(new CompareOptions('out'))
        ->and(CompareOptions::parse(['--filter=Order', 'out', '--processes=1']))->toEqual(new CompareOptions('out', 1, null, null, ['--filter=Order']));
});

it('requires the output directory', function (): void {
    expect(fn (): CompareOptions => CompareOptions::parse(['--processes=2']))
        ->toThrow(InvalidArgumentException::class, 'O comando compare precisa do diretório de saída: sonda compare <saída>.');
});

it('requires a whole number from 1 for the counts', function (string $argument, string $option): void {
    expect(fn (): CompareOptions => CompareOptions::parse(['out', $argument]))
        ->toThrow(InvalidArgumentException::class, "A opção {$option} precisa de um número inteiro a partir de 1.");
})->with([
    ['--processes=0', '--processes'],
    ['--processes=x', '--processes'],
    ['--batch=', '--batch'],
    ['--batch=-1', '--batch'],
]);
