<?php

declare(strict_types=1);

use Sonda\Project\{DirectRequire, DirectRequires};
use Sonda\Scope;

/** @return array{root: string, files: list<string>} an app with helpers required in several ways */
function requiringProject(): array
{
    $root = TemporaryDirectory::create();
    mkdir($root . '/app/Support', 0777, true);
    mkdir($root . '/lib');
    file_put_contents($root . '/app/helpers.php', "<?php\n\nfunction helper(): int\n{\n    return 1;\n}\n");
    file_put_contents($root . '/app/Support/strings.php', "<?php\n\nfunction strings(): int\n{\n    return 2;\n}\n");
    file_put_contents($root . '/lib/outside.php', "<?php\n\nfunction outside(): int\n{\n    return 3;\n}\n");
    file_put_contents($root . '/app/Boot.php', <<<'PHP'
    <?php

    require __DIR__ . '/helpers.php';
    require_once dirname(__FILE__) . '/Support/strings.php';
    include dirname(__DIR__, 1) . '/lib/outside.php';
    include_once __DIR__ . '/missing.php';
    require $dynamic . '/helpers.php';
    require __DIR__ . '/Boot.php';
    require 'helpers.php';
    PHP);
    file_put_contents($root . '/app/Support/Deep.php', "<?php\n\nrequire_once dirname(__DIR__, 2) . '/app/helpers.php';\nrequire dirname(__DIR__) . '/Support/strings.php';\nrequire dirname(__DIR__, 1) . '/helpers.php';\nrequire \$prefix . __DIR__ . '/strings.php';\nrequire \$resolver(__DIR__) . '/strings.php';\n");
    file_put_contents($root . '/app/Inc.php', "<?php\n\ninclude __DIR__ . '/helpers.php';\n");
    file_put_contents($root . '/app/Plain.php', "<?php\n\nfinal class Plain\n{\n}\n");
    $files = [$root . '/app/Boot.php', $root . '/app/Inc.php', $root . '/app/Plain.php', $root . '/app/Support/Deep.php', $root . '/app/Support/strings.php', $root . '/app/helpers.php'];

    return ['root' => (string) realpath($root), 'files' => array_map(static fn (string $file): string => (string) realpath($file), $files)];
}

it('finds the files of the scope required directly by other files of the scope, resolving __DIR__, __FILE__ and dirname()', function (): void {
    ['root' => $root, 'files' => $files] = requiringProject();

    $found = DirectRequires::in($files, new Scope([$root . '/app']));

    expect($found)->toEqual([
        new DirectRequire($root . '/app/Boot.php', 3, $root . '/app/helpers.php'),
        new DirectRequire($root . '/app/Boot.php', 4, $root . '/app/Support/strings.php'),
        new DirectRequire($root . '/app/Boot.php', 9, $root . '/app/helpers.php'),
        new DirectRequire($root . '/app/Inc.php', 3, $root . '/app/helpers.php'),
        new DirectRequire($root . '/app/Support/Deep.php', 3, $root . '/app/helpers.php'),
        new DirectRequire($root . '/app/Support/Deep.php', 4, $root . '/app/Support/strings.php'),
        new DirectRequire($root . '/app/Support/Deep.php', 5, $root . '/app/helpers.php'),
    ]);
});

it('ignores a file that includes nothing', function (): void {
    ['root' => $root] = requiringProject();

    expect(DirectRequires::in([$root . '/app/Plain.php'], new Scope([$root . '/app'])))->toBe([]);
});

it('resolves dirname() only with a literal number of levels', function (): void {
    $root = TemporaryDirectory::create();
    mkdir($root . '/app/Support', 0777, true);
    file_put_contents($root . '/app/helpers.php', '<?php');
    file_put_contents($root . '/app/Support/Deep.php', "<?php\n\nrequire dirname(__DIR__, \$levels) . '/helpers.php';\nrequire dirname() . '/helpers.php';\nrequire dirname(__DIR__, 0) . '/helpers.php';\n");

    expect(DirectRequires::in([(string) realpath($root . '/app/Support/Deep.php')], new Scope([$root . '/app'])))->toBe([]);
});
