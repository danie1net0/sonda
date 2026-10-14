<?php

declare(strict_types=1);

namespace Sonda\Project;

use RuntimeException;

/** The command that runs the project's tests: Laravel's artisan, Pest or PHPUnit, in parallel when ParaTest is there. */
final class Runner
{
    /** @return list<string> */
    public static function command(string $projectRoot, bool $parallel): array
    {
        $parallel = $parallel && is_file($projectRoot . '/vendor/bin/paratest');

        if (is_file($projectRoot . '/artisan')) {
            return [PHP_BINARY, 'artisan', 'test', ...($parallel ? ['--parallel'] : [])];
        }

        if (is_file($projectRoot . '/vendor/bin/pest')) {
            return [PHP_BINARY, 'vendor/bin/pest', ...($parallel ? ['--parallel'] : [])];
        }

        if ($parallel) {
            return [PHP_BINARY, 'vendor/bin/paratest'];
        }

        if (is_file($projectRoot . '/vendor/bin/phpunit')) {
            return [PHP_BINARY, 'vendor/bin/phpunit'];
        }

        throw new RuntimeException("Nenhum runner de testes em {$projectRoot}: esperava artisan, vendor/bin/pest ou vendor/bin/phpunit.");
    }
}
