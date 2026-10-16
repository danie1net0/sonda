<?php

declare(strict_types=1);

use Sonda\Report\ChangeCoverage;

it('carries the gaps of a change and the size of the part it touched', function (): void {
    $change = new ChangeCoverage(['/app/Order.php' => ['lines' => [8], 'branches' => [[8, 8]]]], 6, 3, 3, 2);

    expect($change)
        ->uncovered->toBe(['/app/Order.php' => ['lines' => [8], 'branches' => [[8, 8]]]])
        ->executableLines->toBe(6)
        ->executedLines->toBe(3)
        ->executableBranches->toBe(3)
        ->executedBranches->toBe(2);
});
