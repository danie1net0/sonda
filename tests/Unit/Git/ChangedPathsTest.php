<?php

declare(strict_types=1);

use Sonda\Git\{ChangedPaths, Git};

it('lists every path changed since the merge base: committed, modified, deleted, renamed and new, of any kind', function (): void {
    $repository = TemporaryDirectory::create();
    $git = static fn (string ...$arguments): string => Git::run($repository, [...$arguments]);
    $git('init', '-q', '-b', 'master');
    $git('config', 'user.email', 'tests@example.com');
    $git('config', 'user.name', 'Tests');
    mkdir($repository . '/app');
    file_put_contents($repository . '/.gitignore', "ignored.txt\n");

    foreach (['app/Committed.php', 'app/Modified.php', 'app/Deleted.php', 'app/Renamed.php', 'app/Untouched.php', 'config.yml'] as $file) {
        file_put_contents($repository . '/' . $file, "content of {$file}\n");
    }

    $git('add', '-A');
    $git('commit', '-q', '-m', 'base');
    $git('checkout', '-q', '-b', 'feature');
    file_put_contents($repository . '/app/Committed.php', 'changed');
    $git('commit', '-q', '-am', 'feature');
    file_put_contents($repository . '/app/Modified.php', 'changed');
    file_put_contents($repository . '/config.yml', 'changed');
    file_put_contents($repository . '/notes.md', 'new');
    file_put_contents($repository . '/app/Brand.php', 'new');
    file_put_contents($repository . '/ignored.txt', 'ignored');
    unlink($repository . '/app/Deleted.php');
    $git('mv', 'app/Renamed.php', 'app/Moved.php');
    $real = (string) realpath($repository);

    expect(ChangedPaths::since($repository, 'master'))->toBe([
        $real . '/app/Brand.php',
        $real . '/app/Committed.php',
        $real . '/app/Deleted.php',
        $real . '/app/Modified.php',
        $real . '/app/Moved.php',
        $real . '/app/Renamed.php',
        $real . '/config.yml',
        $real . '/notes.md',
    ]);
});

it('lists nothing when nothing changed', function (): void {
    $repository = TemporaryDirectory::create();
    Git::run($repository, ['init', '-q', '-b', 'master']);
    Git::run($repository, ['config', 'user.email', 'tests@example.com']);
    Git::run($repository, ['config', 'user.name', 'Tests']);
    file_put_contents($repository . '/a.txt', 'a');
    Git::run($repository, ['add', '-A']);
    Git::run($repository, ['commit', '-q', '-m', 'base']);

    expect(ChangedPaths::since($repository, 'master'))->toBe([]);
});

it('names the paths from the top level of the repository for a project in a subdirectory of it', function (): void {
    $repository = TemporaryDirectory::create();
    mkdir($repository . '/project/app', 0777, true);
    Git::run($repository, ['init', '-q', '-b', 'master']);
    Git::run($repository, ['config', 'user.email', 'tests@example.com']);
    Git::run($repository, ['config', 'user.name', 'Tests']);
    file_put_contents($repository . '/project/app/Order.php', 'one');
    Git::run($repository, ['add', '-A']);
    Git::run($repository, ['commit', '-q', '-m', 'base']);
    file_put_contents($repository . '/project/app/Order.php', 'two');
    file_put_contents($repository . '/project/app/Fresh.php', 'new');

    expect(ChangedPaths::since($repository . '/project', 'master'))->toBe([
        $repository . '/project/app/Fresh.php',
        $repository . '/project/app/Order.php',
    ]);
});
