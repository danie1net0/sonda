<?php

declare(strict_types=1);

use Sonda\Report\SourceFiles;

function sourceTree(): string
{
    $directory = TemporaryDirectory::create();

    foreach (['Models/Order.php', 'Actions/Pay.php', 'Providers/AppProvider.php', 'Models/notes.txt', 'Actions/Deep/Refund.php'] as $file) {
        if (!is_dir(dirname($directory . '/' . $file))) {
            mkdir(dirname($directory . '/' . $file), 0777, true);
        }

        file_put_contents($directory . '/' . $file, '<?php');
    }

    return $directory;
}

it('finds the PHP files under a directory, sorted', function (): void {
    $directory = sourceTree();

    expect(SourceFiles::find($directory))->toBe([
        $directory . '/Actions/Deep/Refund.php',
        $directory . '/Actions/Pay.php',
        $directory . '/Models/Order.php',
        $directory . '/Providers/AppProvider.php',
    ]);
});

it('leaves out the excluded directories', function (): void {
    $directory = sourceTree();

    expect(SourceFiles::find($directory, [$directory . '/Providers', $directory . '/Actions/Deep']))->toBe([
        $directory . '/Actions/Pay.php',
        $directory . '/Models/Order.php',
    ]);
});

it('ignores excluded directories that do not exist', function (): void {
    $directory = sourceTree();

    expect(SourceFiles::find($directory, [$directory . '/Console/Commands']))->toHaveCount(4);
});

it('does not exclude a directory that only shares a prefix', function (): void {
    $directory = sourceTree();
    mkdir($directory . '/Model');

    expect(SourceFiles::find($directory, [$directory . '/Model']))->toContain($directory . '/Models/Order.php');
});
