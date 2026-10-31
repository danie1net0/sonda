<?php

declare(strict_types=1);

use Sonda\Git\Git;

it('returns what a git command prints', function (): void {
    $repository = TemporaryDirectory::create();
    Git::run($repository, ['init', '-q', '-b', 'master']);

    expect(Git::run($repository, ['symbolic-ref', '--short', 'HEAD']))->toBe("master\n")
        ->and(Git::run($repository, ['status', '--porcelain']))->toBe('');
});

/** A repository with a commit on master and a branch that adds one, or an orphan branch that shares no history with it. */
function repositoryWithBranch(bool $orphan): string
{
    $repository = TemporaryDirectory::create();
    $git = static fn (string ...$arguments): string => Git::run($repository, [...$arguments]);
    $git('init', '-q', '-b', 'master');
    $git('config', 'user.email', 'tests@example.com');
    $git('config', 'user.name', 'Tests');
    file_put_contents($repository . '/a.txt', 'one');
    $git('add', '-A');
    $git('commit', '-q', '-m', 'base');
    $git('checkout', '-q', ...($orphan ? ['--orphan', 'island'] : ['-b', 'feature']));
    file_put_contents($repository . '/a.txt', 'two');
    $git('add', '-A');
    $git('commit', '-q', '-m', 'change');

    return $repository;
}

it('takes the merge base of a reference and HEAD as the base', function (): void {
    $repository = repositoryWithBranch(orphan: false);

    expect(Git::base($repository, 'master'))->toBe(trim(Git::run($repository, ['rev-parse', 'master'])));
});

it('takes the reference itself as the base when there is no merge base, as in a shallow clone', function (): void {
    $repository = repositoryWithBranch(orphan: true);

    expect(Git::base($repository, 'master'))->toBe(trim(Git::run($repository, ['rev-parse', 'master'])))
        ->and(fn (): string => Git::base($repository, 'nowhere'))->toThrow(RuntimeException::class);
});

it('fails with what git said when the command fails', function (): void {
    $directory = TemporaryDirectory::create();

    try {
        Git::run($directory, ['rev-parse', 'HEAD']);
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toStartWith('git rev-parse HEAD failed: fatal:')
            ->not->toEndWith("\n");

        return;
    }

    throw new LogicException('O git deveria ter falhado.');
});
