<?php

declare(strict_types=1);

use Sonda\Cli\Version;

/** A checkout of sonda in a git repository with one commit, tagged when asked. */
function checkout(?string $tag): string
{
    $directory = TemporaryDirectory::create();
    file_put_contents($directory . '/README.md', 'sonda');
    $git = 'git -C ' . escapeshellarg($directory) . ' -c user.name=t -c user.email=t@t';
    exec("{$git} init -q && {$git} add . && {$git} commit -q -m initial" . ($tag !== null ? " && {$git} tag {$tag}" : ''));

    return $directory;
}

it('reads the version Composer installed beside it', function (): void {
    $vendor = TemporaryDirectory::create() . '/vendor';
    mkdir($vendor . '/ddr/sonda', 0o755, true);
    mkdir($vendor . '/composer');
    file_put_contents($vendor . '/composer/installed.php', "<?php return ['versions' => ['ddr/sonda' => ['pretty_version' => '1.14.5']]];");

    expect(Version::of($vendor . '/ddr/sonda'))->toBe('1.14.5');
});

it('falls back to the checkout when Composer does not list it', function (): void {
    $vendor = TemporaryDirectory::create() . '/vendor';
    mkdir($vendor . '/ddr/sonda', 0o755, true);
    mkdir($vendor . '/composer');
    file_put_contents($vendor . '/composer/installed.php', "<?php return ['versions' => []];");

    expect(Version::of($vendor . '/ddr/sonda'))->toBe('desconhecida');
});

it('describes a checkout by its tag, without the v, and by the commit before any tag', function (): void {
    $tagged = checkout('v2.0.0');
    $untagged = checkout(null);

    expect(Version::of($tagged))->toBe('2.0.0')
        ->and(Version::of($untagged))->toMatch('/^[0-9a-f]{7,}$/');
});

it('is unknown outside a checkout', function (): void {
    expect(Version::of(TemporaryDirectory::create()))->toBe('desconhecida');
});
