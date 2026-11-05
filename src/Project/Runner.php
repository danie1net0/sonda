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
        $runner = self::executable($projectRoot, $parallel);
        $command = [];
        $command[] = PHP_BINARY;
        $command[] = $runner;

        if ($runner === 'artisan') {
            $command[] = 'test';
        }

        if ($parallel && $runner !== 'vendor/bin/paratest') {
            $command[] = '--parallel';
        }

        return $command;
    }

    private static function executable(string $projectRoot, bool $parallel): string
    {
        if (is_file($projectRoot . '/artisan')) {
            return 'artisan';
        }

        if (is_file($projectRoot . '/vendor/bin/pest')) {
            return 'vendor/bin/pest';
        }

        if ($parallel) {
            return 'vendor/bin/paratest';
        }

        if (is_file($projectRoot . '/vendor/bin/phpunit')) {
            return 'vendor/bin/phpunit';
        }

        throw new RuntimeException("No test runner in {$projectRoot}: expected artisan, vendor/bin/pest or vendor/bin/phpunit.");
    }
}
