<?php

declare(strict_types=1);

use Sonda\Git\Git;

it('returns what a git command prints', function (): void {
    $repository = TemporaryDirectory::create();
    Git::run($repository, ['init', '-q', '-b', 'master']);

    expect(Git::run($repository, ['symbolic-ref', '--short', 'HEAD']))->toBe("master\n")
        ->and(Git::run($repository, ['status', '--porcelain']))->toBe('');
});

it('fails with what git said when the command fails', function (): void {
    $directory = TemporaryDirectory::create();

    try {
        Git::run($directory, ['rev-parse', 'HEAD']);
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toStartWith('git rev-parse HEAD falhou: fatal:')
            ->not->toEndWith("\n");

        return;
    }

    throw new LogicException('O git deveria ter falhado.');
});
