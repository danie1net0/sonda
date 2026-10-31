<?php

declare(strict_types=1);

use Sonda\Cli\RunnerInvocation;

it('carries the runner arguments and the generated configuration, none by default', function (): void {
    $plain = new RunnerInvocation(['tests/Unit']);
    $generated = new RunnerInvocation(['--configuration=/tmp/phpunit.xml'], '/tmp/phpunit.xml');

    expect($plain->arguments)->toBe(['tests/Unit'])
        ->and($plain->generatedConfiguration)->toBeNull()
        ->and($generated->arguments)->toBe(['--configuration=/tmp/phpunit.xml'])
        ->and($generated->generatedConfiguration)->toBe('/tmp/phpunit.xml');
});
