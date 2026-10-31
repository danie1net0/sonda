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
        $command = [];
        $command[] = PHP_BINARY;

        if (is_file($projectRoot . '/artisan')) {
            $command[] = 'artisan';
            $command[] = 'test';
        } elseif (is_file($projectRoot . '/vendor/bin/pest')) {
            $command[] = 'vendor/bin/pest';
        } elseif ($parallel) {
            $command[] = 'vendor/bin/paratest';

            return $command;
        } elseif (is_file($projectRoot . '/vendor/bin/phpunit')) {
            $command[] = 'vendor/bin/phpunit';

            return $command;
        } else {
            throw new RuntimeException("No test runner in {$projectRoot}: expected artisan, vendor/bin/pest or vendor/bin/phpunit.");
        }

        if ($parallel) {
            $command[] = '--parallel';
        }

        return $command;
    }
}
