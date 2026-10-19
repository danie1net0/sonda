<?php

declare(strict_types=1);

use Sonda\Hits;

require __DIR__ . '/Support/helpers.php';

pest()
    ->beforeEach(function (): void {
        Hits::$current = [];
        Hits::$accumulated = [];
        Hits::$perTest = false;
        Hits::$tests = [];
        Hits::$failed = [];
        new ReflectionProperty(Hits::class, 'packed')->setValue(null, []);
    })
    ->afterEach(function (): void {
        foreach (TemporaryDirectory::$created as $directory) {
            TemporaryDirectory::remove($directory);
        }

        TemporaryDirectory::$created = [];
    })
    ->in('Unit', 'Feature');
