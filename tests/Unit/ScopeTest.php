<?php

declare(strict_types=1);

use Sonda\Scope;

function scopeTree(): string
{
    $directory = TemporaryDirectory::create();

    foreach (['app/Models/Order.php', 'app/Providers/AppProvider.php', 'lib/Helper.php', 'application/Invoice.php'] as $file) {
        mkdir(dirname($directory . '/' . $file), 0777, true);
        file_put_contents($directory . '/' . $file, '<?php');
    }

    return $directory;
}

it('includes the files under a target directory and no others', function (): void {
    $directory = scopeTree();
    $scope = new Scope([$directory . '/app']);

    expect($scope->includes($directory . '/app/Models/Order.php'))->toBeTrue()
        ->and($scope->includes($directory . '/app/Providers/AppProvider.php'))->toBeTrue()
        ->and($scope->includes($directory . '/lib/Helper.php'))->toBeFalse()
        ->and($scope->includes($directory . '/application/Invoice.php'))->toBeFalse()
        ->and($scope->narrowsToFiles())->toBeFalse();
});

it('leaves out the excluded directories', function (): void {
    $directory = scopeTree();
    $scope = new Scope([$directory . '/app'], [$directory . '/app/Providers', $directory . '/missing']);

    expect($scope->includes($directory . '/app/Models/Order.php'))->toBeTrue()
        ->and($scope->includes($directory . '/app/Providers/AppProvider.php'))->toBeFalse()
        ->and($scope->excludedDirectories())->toBe([$directory . '/app/Providers/']);
});

it('narrows to the given files when there are any', function (): void {
    $directory = scopeTree();
    $scope = new Scope([$directory . '/app'], [], [$directory . '/app/Models/Order.php', $directory . '/missing.php']);

    expect($scope->narrowsToFiles())->toBeTrue()
        ->and($scope->includes($directory . '/app/Models/Order.php'))->toBeTrue()
        ->and($scope->includes($directory . '/app/Providers/AppProvider.php'))->toBeFalse();
});

it('takes several target directories, resolved to real paths', function (): void {
    $directory = scopeTree();
    $scope = new Scope([$directory . '/app', $directory . '/lib/../lib']);

    expect($scope->targetDirectories())->toBe([$directory . '/app/', $directory . '/lib/'])
        ->and($scope->includes($directory . '/lib/Helper.php'))->toBeTrue();
});

it('rejects a target directory that does not exist', function (): void {
    expect(fn (): Scope => new Scope(['/missing/app']))
        ->toThrow(InvalidArgumentException::class, 'O diretório /missing/app não existe.');
});

it('is not narrowed when every given file is missing', function (): void {
    $directory = scopeTree();
    $scope = new Scope([$directory . '/app'], [], [$directory . '/missing.php']);

    expect($scope->narrowsToFiles())->toBeFalse()
        ->and($scope->includes($directory . '/app/Models/Order.php'))->toBeTrue();
});
