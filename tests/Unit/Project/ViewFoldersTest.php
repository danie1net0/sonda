<?php

declare(strict_types=1);

use Sonda\Project\ViewFolders;

it('finds resources/views of the project and of the packages and modules kept in it, not of its dependencies', function (): void {
    $root = TemporaryDirectory::create();

    foreach (['resources/views', 'Modules/Blog/resources/views', 'packages/ui/resources/views', 'app/views', 'resources/js', 'vendor/acme/pkg/resources/views', 'node_modules/x/resources/views', 'storage/framework/views', 'tests/Fixtures/resources/views', 'public/resources/views', 'bootstrap/cache/resources/views', '.git/x/resources/views', 'x/y/z/resources/views', 'vendor/pkg/resources/views', 'storage/app/resources/views', 'src/resources/views/nested/resources/views'] as $directory) {
        mkdir($root . '/' . $directory, 0777, true);
    }

    file_put_contents($root . '/resources/views.txt', '');
    $real = (string) realpath($root);

    expect(ViewFolders::in($root))->toBe([
        $real . '/Modules/Blog/resources/views',
        $real . '/packages/ui/resources/views',
        $real . '/resources/views',
        $real . '/src/resources/views',
    ]);
});

it('finds nothing in a project without views', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/app');

    expect(ViewFolders::in($root))->toBe([]);
});
