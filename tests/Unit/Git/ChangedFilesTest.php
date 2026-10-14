<?php

declare(strict_types=1);

use Sonda\Git\{ChangedFiles, Git};

/** A repository with master and a branch that changed, added, deleted and left files alone. */
function repositoryWithChanges(): string
{
    $repository = TemporaryDirectory::create();
    $git = static fn (string ...$arguments): string => Git::run($repository, [...$arguments]);
    $git('init', '-q', '-b', 'master');
    $git('config', 'user.email', 'tests@example.com');
    $git('config', 'user.name', 'Tests');
    mkdir($repository . '/app');

    file_put_contents($repository . '/.gitignore', "app/Ignored.php\n");

    foreach (['app/Committed.php', 'app/Modified.php', 'app/Deleted.php', 'app/Untouched.php', 'app/Renamed.php', 'notes.txt'] as $file) {
        file_put_contents($repository . '/' . $file, "<?php // {$file}\n");
    }

    $git('add', '-A');
    $git('commit', '-q', '-m', 'base');
    $git('checkout', '-q', '-b', 'feature');
    file_put_contents($repository . '/app/Committed.php', "<?php // changed\n");
    file_put_contents($repository . '/app/Added.php', "<?php\n");
    $git('add', '-A');
    $git('commit', '-q', '-m', 'feature');
    $git('checkout', '-q', 'master');
    file_put_contents($repository . '/app/Untouched.php', "<?php // master moved on\n");
    $git('commit', '-q', '-am', 'master moves on');
    $git('checkout', '-q', 'feature');

    file_put_contents($repository . '/app/Modified.php', "<?php // modified in the working tree\n");
    file_put_contents($repository . '/app/Brand.php', "<?php // untracked, sorts before the committed ones\n");
    file_put_contents($repository . '/app/Ignored.php', "<?php // untracked and ignored\n");
    file_put_contents($repository . '/notes.txt', 'changed text');
    unlink($repository . '/app/Deleted.php');
    $git('mv', 'app/Renamed.php', 'app/Moved.php');

    return $repository;
}

it('lists the PHP files changed since the merge base: committed, modified, new and renamed', function (): void {
    $repository = repositoryWithChanges();
    $real = (string) realpath($repository);

    expect(ChangedFiles::since($repository, 'master'))->toBe([
        $real . '/app/Added.php',
        $real . '/app/Brand.php',
        $real . '/app/Committed.php',
        $real . '/app/Modified.php',
        $real . '/app/Moved.php',
    ]);
});

it('lists nothing when nothing changed', function (): void {
    $repository = TemporaryDirectory::create();
    Git::run($repository, ['init', '-q', '-b', 'master']);
    Git::run($repository, ['config', 'user.email', 'tests@example.com']);
    Git::run($repository, ['config', 'user.name', 'Tests']);
    file_put_contents($repository . '/a.php', '<?php');
    Git::run($repository, ['add', '-A']);
    Git::run($repository, ['commit', '-q', '-m', 'base']);

    expect(ChangedFiles::since($repository, 'master'))->toBe([]);
});
