<?php

declare(strict_types=1);

use Sonda\Cli\MemoryLimit;

/** Runs the check with this process at the given limit, and gives back the limit it ends with. */
function memoryLimitAfter(string $current, ?string $wanted): string
{
    $before = ini_get('memory_limit');
    ini_set('memory_limit', $current);

    try {
        MemoryLimit::raiseTo($wanted);

        return ini_get('memory_limit');
    } finally {
        ini_set('memory_limit', $before);
    }
}

it('raises the limit to the one the project gives its tests', function (): void {
    expect(memoryLimitAfter('2G', '3G'))->toBe('3G')
        ->and(memoryLimitAfter('2G', '-1'))->toBe('-1');
});

it('never lowers the limit', function (): void {
    expect(memoryLimitAfter('3G', '2G'))->toBe('3G')
        ->and(memoryLimitAfter('3G', '3G'))->toBe('3G')
        ->and(memoryLimitAfter('-1', '3G'))->toBe('-1');
});

it('leaves the limit alone when the project sets none', function (): void {
    expect(memoryLimitAfter('2G', null))->toBe('2G');
});
