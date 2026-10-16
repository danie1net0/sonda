<?php

declare(strict_types=1);

use Sonda\{ComposerFiles, Scope};

/** @return array{root: string, autoload: string} a vendor with a files listing: one file in app, one outside, one gone */
function vendorWithFiles(): array
{
    $root = TemporaryDirectory::create();
    mkdir($root . '/app');
    mkdir($root . '/lib');
    mkdir($root . '/vendor/composer', 0777, true);
    file_put_contents($root . '/app/helpers.php', "<?php\n\nfunction app_helper(): int\n{\n    return 1;\n}\n");
    file_put_contents($root . '/lib/helpers.php', "<?php\n\nfunction lib_helper(): int\n{\n    return 2;\n}\n");
    file_put_contents($root . '/vendor/composer/autoload_files.php', <<<'LISTING'
    <?php

    $vendorDir = dirname(__DIR__);
    $baseDir = dirname($vendorDir);

    return array(
        'app1' => $baseDir . '/app/helpers.php',
        'lib1' => $baseDir . '/lib/helpers.php',
        'gone' => $baseDir . '/app/gone.php',
        7 => 42,
    );
    LISTING);

    return ['root' => $root, 'autoload' => $root . '/vendor/autoload.php'];
}

/** Composer marks its own files in the test process: the tests put that back as it was. */
beforeEach(function (): void {
    $this->loadedBefore = $GLOBALS['__composer_autoload_files'] ?? null;
});

afterEach(function (): void {
    $GLOBALS['__composer_autoload_files'] = $this->loadedBefore;

    if ($this->loadedBefore === null) {
        unset($GLOBALS['__composer_autoload_files']);
    }
});

it('claims the files of the scope as loaded, so Composer skips them, and names them in order', function (): void {
    ['root' => $root, 'autoload' => $autoload] = vendorWithFiles();

    $claimed = ComposerFiles::claim($autoload, new Scope([$root . '/app']));

    expect($claimed)->toBe([(string) realpath($root . '/app/helpers.php')])
        ->and($GLOBALS['__composer_autoload_files'])->toHaveKey('app1', true)
        ->not->toHaveKey('lib1')
        ->not->toHaveKey('gone')
        ->not->toHaveKey(7);
});

it('keeps what Composer had already marked, and starts from nothing when it marked nothing', function (): void {
    ['root' => $root, 'autoload' => $autoload] = vendorWithFiles();
    $GLOBALS['__composer_autoload_files'] = ['other' => true];

    ComposerFiles::claim($autoload, new Scope([$root . '/app']));
    $kept = $GLOBALS['__composer_autoload_files'];
    unset($GLOBALS['__composer_autoload_files']);
    ComposerFiles::claim($autoload, new Scope([$root . '/app']));

    expect($kept)->toBe(['other' => true, 'app1' => true])
        ->and($GLOBALS['__composer_autoload_files'])->toBe(['app1' => true]);
});

it('claims nothing without a files listing', function (): void {
    $before = $GLOBALS['__composer_autoload_files'] ?? null;

    expect(ComposerFiles::claim(TemporaryDirectory::create() . '/vendor/autoload.php', new Scope([TemporaryDirectory::create()])))->toBe([])
        ->and($GLOBALS['__composer_autoload_files'] ?? null)->toBe($before);
});

it('claims nothing from a listing that is not a list of files', function (): void {
    ['root' => $root, 'autoload' => $autoload] = vendorWithFiles();
    file_put_contents($root . '/vendor/composer/autoload_files.php', "<?php\n\nreturn 'broken';\n");

    expect(ComposerFiles::claim($autoload, new Scope([$root . '/app'])))->toBe([]);
});

it('lists the files Composer would require that exist, by identifier, without claiming them', function (): void {
    ['root' => $root, 'autoload' => $autoload] = vendorWithFiles();
    $before = $GLOBALS['__composer_autoload_files'] ?? null;

    expect(ComposerFiles::listed($autoload))->toBe(['app1' => (string) realpath($root . '/app/helpers.php'), 'lib1' => (string) realpath($root . '/lib/helpers.php')])
        ->and($GLOBALS['__composer_autoload_files'] ?? null)->toBe($before);
});
