<?php

declare(strict_types=1);

use Sonda\Git\{ChangedLines, Git};

/**
 * A repository with master and a branch whose changes replace one line, add two, drop one, create
 * files committed and untracked, delete and rename others, and leave one alone.
 */
function repositoryWithLineChanges(): string
{
    $repository = TemporaryDirectory::create();
    $git = static fn (string ...$arguments): string => Git::run($repository, [...$arguments]);
    $git('init', '-q', '-b', 'master');
    $git('config', 'user.email', 'tests@example.com');
    $git('config', 'user.name', 'Tests');
    mkdir($repository . '/app');
    file_put_contents($repository . '/.gitignore', "app/Ignored.php\n");
    $tenLines = implode("\n", array_map(static fn (int $line): string => "// line {$line}", range(1, 10))) . "\n";

    foreach (['app/Service.php', 'app/Modified.php', 'app/Shrunk.php', 'app/Deleted.php', 'app/Untouched.php', 'app/Renamed.php', 'notes.txt'] as $file) {
        file_put_contents($repository . '/' . $file, "<?php\n" . $tenLines);
    }

    $git('add', '-A');
    $git('commit', '-q', '-m', 'base');
    $git('checkout', '-q', '-b', 'feature');
    // Service.php: line 3 replaced, two lines inserted after line 7 (so 8 and 9 are new).
    file_put_contents($repository . '/app/Service.php', "<?php\n// line 1\n// changed 2\n// line 3\n// line 4\n// line 5\n// line 6\n// new 7\n// new 8\n// line 7\n// line 8\n// line 9\n// line 10\n");
    file_put_contents($repository . '/app/Added.php', "<?php\n// one\n// two\n");
    $git('add', '-A');
    $git('commit', '-q', '-m', 'feature');
    $git('checkout', '-q', 'master');
    file_put_contents($repository . '/app/Untouched.php', "<?php\n// master moved on\n" . $tenLines);
    $git('commit', '-q', '-am', 'master moves on');
    $git('checkout', '-q', 'feature');

    // Working tree: one line replaced, one line dropped, an untracked file, an ignored one, a deletion and a rename.
    file_put_contents($repository . '/app/Modified.php', "<?php\n// line 1\n// line 2\n// line 3\n// line 4\n// modified 5\n// line 6\n// line 7\n// line 8\n// line 9\n// line 10\n");
    file_put_contents($repository . '/app/Shrunk.php', "<?php\n// line 1\n// line 2\n// line 4\n// line 5\n// line 6\n// line 7\n// line 8\n// line 9\n// line 10\n");
    file_put_contents($repository . '/app/Brand.php', "<?php\n// untracked\n");
    file_put_contents($repository . '/app/Ignored.php', "<?php\n// untracked and ignored\n");
    file_put_contents($repository . '/notes.txt', 'changed text');
    unlink($repository . '/app/Deleted.php');
    $git('mv', 'app/Renamed.php', 'app/Moved.php');

    return $repository;
}

it('maps each changed PHP file to the lines its change added or replaced, and a new file to null', function (): void {
    $repository = repositoryWithLineChanges();
    $real = (string) realpath($repository);

    expect(ChangedLines::since($repository, 'master'))->toBe([
        $real . '/app/Added.php' => [1, 2, 3],
        $real . '/app/Brand.php' => null,
        $real . '/app/Modified.php' => [6],
        $real . '/app/Moved.php' => [],
        $real . '/app/Service.php' => [3, 8, 9],
        $real . '/app/Shrunk.php' => [],
    ]);
});

it('maps nothing when nothing changed', function (): void {
    $repository = TemporaryDirectory::create();
    Git::run($repository, ['init', '-q', '-b', 'master']);
    Git::run($repository, ['config', 'user.email', 'tests@example.com']);
    Git::run($repository, ['config', 'user.name', 'Tests']);
    file_put_contents($repository . '/a.php', '<?php');
    Git::run($repository, ['add', '-A']);
    Git::run($repository, ['commit', '-q', '-m', 'base']);

    expect(ChangedLines::since($repository, 'master'))->toBe([]);
});

it('takes the reference itself as the base when there is no merge base, as in a shallow clone', function (): void {
    $repository = TemporaryDirectory::create();
    $git = static fn (string ...$arguments): string => Git::run($repository, [...$arguments]);
    $git('init', '-q', '-b', 'master');
    $git('config', 'user.email', 'tests@example.com');
    $git('config', 'user.name', 'Tests');
    file_put_contents($repository . '/a.php', "<?php\n// one\n");
    $git('add', '-A');
    $git('commit', '-q', '-m', 'base');
    // An orphan branch shares no history with master: merge-base fails, as it does between the shallow commits of CI.
    $git('checkout', '-q', '--orphan', 'island');
    file_put_contents($repository . '/a.php', "<?php\n// two\n");
    $git('add', '-A');
    $git('commit', '-q', '-m', 'island');
    $real = (string) realpath($repository);

    expect(ChangedLines::since($repository, 'master'))->toBe([$real . '/a.php' => [2]])
        ->and(fn (): array => ChangedLines::since($repository, 'nowhere'))->toThrow(RuntimeException::class);
});

it('finds the files and lines from the top level of the repository for a project in a subdirectory of it', function (): void {
    $repository = TemporaryDirectory::create();
    mkdir($repository . '/project/app', 0777, true);
    Git::run($repository, ['init', '-q', '-b', 'master']);
    Git::run($repository, ['config', 'user.email', 'tests@example.com']);
    Git::run($repository, ['config', 'user.name', 'Tests']);
    file_put_contents($repository . '/project/app/Order.php', "<?php\n// one\n// two\n");
    Git::run($repository, ['add', '-A']);
    Git::run($repository, ['commit', '-q', '-m', 'base']);
    file_put_contents($repository . '/project/app/Order.php', "<?php\n// one\n// changed\n");
    file_put_contents($repository . '/project/app/Fresh.php', "<?php\n");

    expect(ChangedLines::since($repository . '/project', 'master'))->toBe([
        $repository . '/project/app/Fresh.php' => null,
        $repository . '/project/app/Order.php' => [3],
    ]);
});
