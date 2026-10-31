<?php

declare(strict_types=1);

use Sonda\Filesystem;

it('reads a file', function (): void {
    $path = TemporaryDirectory::create() . '/file.txt';
    file_put_contents($path, 'conteúdo');

    expect(Filesystem::read($path))->toBe('conteúdo');
});

it('fails to read a missing file', function (): void {
    expect(fn (): string => Filesystem::read('/missing/file.txt'))
        ->toThrow(RuntimeException::class, 'The file /missing/file.txt could not be read.');
});

it('removes a directory with everything in it', function (): void {
    $directory = TemporaryDirectory::create() . '/tree';
    mkdir($directory . '/nested/deeper', 0777, true);
    file_put_contents($directory . '/nested/deeper/file.txt', 'x');
    file_put_contents($directory . '/top.txt', 'y');

    Filesystem::removeDirectory($directory);
    Filesystem::removeDirectory($directory);

    expect(is_dir($directory))->toBeFalse();
});

it('creates a private directory, parents included, and accepts it again', function (): void {
    $directory = TemporaryDirectory::create() . '/nested/private';

    Filesystem::privateDirectory($directory);
    Filesystem::privateDirectory($directory);

    expect(is_dir($directory))->toBeTrue()
        ->and(fileperms($directory) & 0o777)->toBe(0o700);
});

it('refuses a directory open to other users', function (): void {
    $directory = TemporaryDirectory::create() . '/shared';
    mkdir($directory, 0o755);

    expect(fn () => Filesystem::privateDirectory($directory))
        ->toThrow(RuntimeException::class, "The directory {$directory} must belong to the current user and be private: chmod 700 on it fixes that.");
});

it('refuses a directory of another user', function (): void {
    expect(fn () => Filesystem::privateDirectory('/usr'))
        ->toThrow(RuntimeException::class, 'The directory /usr must belong to the current user and be private: chmod 700 on it fixes that.');
})->skip(getmyuid() === 0, 'root owns everything');

it('fails when the directory cannot be created', function (): void {
    expect(fn () => Filesystem::privateDirectory('/usr/sonda-cannot-create'))
        ->toThrow(RuntimeException::class, 'The directory /usr/sonda-cannot-create could not be created.');
})->skip(getmyuid() === 0, 'root creates anywhere');

it('refuses a directory other users can enter', function (): void {
    $directory = TemporaryDirectory::create() . '/enterable';
    mkdir($directory, 0o701);

    expect(fn () => Filesystem::privateDirectory($directory))
        ->toThrow(RuntimeException::class, "The directory {$directory} must belong to the current user and be private: chmod 700 on it fixes that.");
});

it('creates a directory with its parents, and leaves an existing one as it is', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/shared', 0o755);

    Filesystem::ensureDirectory($root . '/nested/deeper');
    Filesystem::ensureDirectory($root . '/shared');

    expect(is_dir($root . '/nested/deeper'))->toBeTrue()
        ->and(fileperms($root . '/nested/deeper') & 0o777)->toBe(0o700)
        ->and(fileperms($root . '/shared') & 0o777)->toBe(0o755);
});

it('fails when a directory cannot be ensured, without letting the warning through', function (): void {
    error_clear_last();

    expect(fn () => Filesystem::ensureDirectory('/usr/sonda-cannot-ensure'))
        ->toThrow(RuntimeException::class, 'The directory /usr/sonda-cannot-ensure could not be created.')
        ->and(error_get_last())->toBeNull();
})->skip(getmyuid() === 0, 'root creates anywhere');
