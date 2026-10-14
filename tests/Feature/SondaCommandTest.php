<?php

declare(strict_types=1);

use Sonda\Git\Git;

/**
 * A project in its own directory, built from the fixture: the sonda vendor stands in for its
 * vendor/, with the PHPUnit and ParaTest binaries linked in.
 */
function sondaProject(): string
{
    $root = TemporaryDirectory::create();
    $sonda = dirname(__DIR__, 2);
    $fixture = __DIR__ . '/../Fixtures/Project';
    mkdir($root . '/vendor/bin', 0777, true);

    foreach (['src', 'src/Ignored', 'tests'] as $directory) {
        if (! is_dir($root . '/' . $directory)) {
            mkdir($root . '/' . $directory);
        }

        foreach (glob($fixture . '/' . $directory . '/*.php') ?: [] as $file) {
            copy($file, $root . '/' . $directory . '/' . basename($file));
        }
    }

    file_put_contents($root . '/vendor/autoload.php', <<<PHP
    <?php
    \$loader = require '{$sonda}/vendor/autoload.php';
    \$loader->addPsr4('Fixture\\\\Project\\\\', __DIR__ . '/../src');
    \$loader->addPsr4('Fixture\\\\Project\\\\Tests\\\\', __DIR__ . '/../tests');

    return \$loader;
    PHP);
    symlink($sonda . '/vendor/bin/phpunit', $root . '/vendor/bin/phpunit');
    symlink($sonda . '/vendor/bin/paratest', $root . '/vendor/bin/paratest');
    file_put_contents($root . '/phpunit.xml', <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <phpunit bootstrap="vendor/autoload.php" cacheResult="false">
        <testsuites>
            <testsuite name="Project"><directory>tests</directory></testsuite>
        </testsuites>
        <source>
            <include><directory>src</directory></include>
            <exclude><directory>src/Ignored</directory></exclude>
        </source>
    </phpunit>
    XML);

    return $root;
}

/** @return array{0: int, 1: string} the exit code and everything the command printed */
function sonda(string $root, string ...$arguments): array
{
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__, 2) . '/bin/sonda', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        [...getenv(), 'XDG_CACHE_HOME' => $root . '/.cache'],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar o sonda.');
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);

    return [proc_close($process), $output];
}

function commitEverything(string $root): void
{
    Git::run($root, ['init', '-q', '-b', 'master']);
    Git::run($root, ['config', 'user.email', 'tests@example.com']);
    Git::run($root, ['config', 'user.name', 'Tests']);
    Git::run($root, ['add', '-A']);
    Git::run($root, ['commit', '-q', '-m', 'base']);
}

it('measures a project in parallel with nothing installed in it', function (): void {
    [$status, $output] = sonda(sondaProject(), 'test');

    expect($status)->toBe(0)
        ->and($output)->toContain('OK (2 tests, 3 assertions)')
        ->toContain("\nCobertura: linhas 3/5 (60,00%), branches 4/7 (57,14%)\n");
});

it('measures a project with plain PHPUnit when asked not to run in parallel', function (): void {
    [$status, $output] = sonda(sondaProject(), 'test', '--no-parallel', '--html=build/html');

    expect($status)->toBe(0)
        ->and($output)->toContain('Cobertura: linhas 3/5 (60,00%), branches 4/7 (57,14%)')
        ->toContain('Relatório HTML em ');
});

it('fails on the uncovered parts of the files changed since a reference', function (): void {
    $root = sondaProject();
    commitEverything($root);
    file_put_contents($root . '/src/Shipping.php', str_replace('final class', "/** changed */\nfinal class", (string) file_get_contents($root . '/src/Shipping.php')));

    [$status, $output] = sonda($root, 'test', '--changed=master');

    expect($status)->toBe(1)
        ->and($output)->toContain("Cobertura: linhas 1/1 (100,00%), branches 2/3 (66,67%)\nSem cobertura:\nsrc/Shipping.php: branches 12\n");
});

it('runs nothing when the changes are outside the source', function (): void {
    $root = sondaProject();
    commitEverything($root);
    file_put_contents($root . '/src/Ignored/Legacy.php', "<?php\n// changed\n");

    [$status, $output] = sonda($root, 'test', '--changed=master');

    expect($status)->toBe(0)
        ->and($output)->toBe("Nenhum arquivo alterado dentro de <source>: nada a medir.\n");
});

it('refuses to run outside a project', function (): void {
    [$status, $output] = sonda(TemporaryDirectory::create(), 'test');

    expect($status)->toBe(1)
        ->and($output)->toBe("Rode o sonda na raiz de um projeto com vendor/autoload.php.\n");
});
