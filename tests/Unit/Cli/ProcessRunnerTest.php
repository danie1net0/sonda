<?php

declare(strict_types=1);

use Sonda\Cli\{ProcessRunner, SystemProcessRunner};

it('is the contract the system runner and the test doubles fulfil', function (): void {
    $run = new ReflectionMethod(ProcessRunner::class, 'run');

    expect(new SystemProcessRunner(STDIN, STDOUT, STDERR))->toBeInstanceOf(ProcessRunner::class)
        ->and(array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $run->getParameters()))->toBe(['command', 'environment', 'directory'])
        ->and((string) $run->getReturnType())->toBe('int');
});
