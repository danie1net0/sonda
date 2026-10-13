<?php

declare(strict_types=1);

use CovProto\Hits;

require __DIR__ . '/Support/helpers.php';

pest()
    ->beforeEach(function (): void {
        Hits::$current     = [];
        Hits::$accumulated = [];
        Hits::$perTest     = false;
    })
    ->afterEach(function (): void {
        foreach (TemporaryDirectory::$created as $directory) {
            TemporaryDirectory::remove($directory);
        }

        TemporaryDirectory::$created = [];
    })
    ->in('Unit', 'Feature');
