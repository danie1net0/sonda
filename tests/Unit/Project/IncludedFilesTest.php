<?php

declare(strict_types=1);

use Sonda\Project\IncludedFiles;

it('picks the files that declare no class, interface, trait or enum by name', function (): void {
    $directory = TemporaryDirectory::create();
    $files = [
        'Order.php' => "<?php\n\nfinal readonly class Order\n{\n}\n",
        'Contract.php' => "<?php\n\ninterface Contract\n{\n}\n",
        'Named.php' => "<?php\n\ntrait Named\n{\n}\n",
        'Status.php' => "<?php\n\nenum Status\n{\n    case On;\n}\n",
        'migration.php' => "<?php\n\n// Declares a class only in this comment: class Fake\nreturn new class () {\n};\n",
        'routes.php' => "<?php\n\n\$router = Order::class;\n",
        'commented.php' => "<?php\n\nreturn new /* anonymous */ class () {\n};\n",
        'documented.php' => "<?php\n\nreturn new /** anonymous */ class () {\n};\n",
        'helpers.php' => "<?php\n\nfunction helper(): int\n{\n    return 1;\n}\n",
        'home.blade.php' => "<h1>Home</h1>\n",
    ];

    foreach ($files as $name => $code) {
        file_put_contents($directory . '/' . $name, $code);
    }

    $paths = array_map(static fn (string $name): string => $directory . '/' . $name, array_keys($files));

    expect(IncludedFiles::in($paths))->toBe([$directory . '/migration.php', $directory . '/routes.php', $directory . '/commented.php', $directory . '/documented.php', $directory . '/helpers.php'])
        ->and(IncludedFiles::in([]))->toBe([]);
});
