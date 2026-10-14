<?php

declare(strict_types=1);

use Sonda\Cli\WorkDirectory;

it('prefers the XDG cache directory', function (): void {
    expect(WorkDirectory::default(['XDG_CACHE_HOME' => '/xdg', 'HOME' => '/home/user']))->toBe('/xdg/sonda');
});

it('falls back to the cache directory under HOME', function (): void {
    expect(WorkDirectory::default(['XDG_CACHE_HOME' => '', 'HOME' => '/home/user']))->toBe('/home/user/.cache/sonda');
});

it('falls back to a temporary directory of the user when there is no HOME', function (): void {
    expect(WorkDirectory::default(['HOME' => '']))->toBe(sys_get_temp_dir() . '/sonda-' . getmyuid());
});

it('falls back to the temporary directory of the user without any environment', function (): void {
    expect(WorkDirectory::default([]))->toBe(sys_get_temp_dir() . '/sonda-' . getmyuid());
});
