<?php

declare(strict_types=1);

use Fixture\Project\Tests\{DiscountTest, ShippingTest};
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

    // Composer's own autoload.php also requires the `files` entries it skips when already claimed.
    file_put_contents($root . '/vendor/autoload.php', <<<PHP
    <?php
    \$loader = require '{$sonda}/vendor/autoload.php';
    \$loader->addPsr4('Fixture\\\\Project\\\\', __DIR__ . '/../src');
    \$loader->addPsr4('Fixture\\\\Project\\\\Tests\\\\', __DIR__ . '/../tests');

    if (is_file(__DIR__ . '/composer/autoload_files.php')) {
        foreach (require __DIR__ . '/composer/autoload_files.php' as \$identifier => \$file) {
            if (empty(\$GLOBALS['__composer_autoload_files'][\$identifier])) {
                \$GLOBALS['__composer_autoload_files'][\$identifier] = true;
                require \$file;
            }
        }
    }

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

/**
 * The project as a Laravel one: an artisan file, a Blade view, and a test that renders it the way
 * Laravel does, compiling it when it is missing, from a subdirectory per worker under parallel testing.
 */
function laravelLikeProject(): string
{
    $root = sondaProject();
    // Stands in for Laravel's artisan: `artisan test [--parallel]` runs ParaTest or PHPUnit with the other arguments.
    file_put_contents($root . '/artisan', <<<'ARTISAN'
    <?php
    $arguments = array_slice($argv, 2);
    $parallel = in_array('--parallel', $arguments, true);
    $runner = __DIR__ . '/vendor/bin/' . ($parallel ? 'paratest' : 'phpunit');
    $command = [PHP_BINARY, $runner, ...array_filter($arguments, static fn (string $argument): bool => $argument !== '--parallel')];
    passthru(implode(' ', array_map(escapeshellarg(...), $command)), $status);
    exit($status);
    ARTISAN);
    mkdir($root . '/resources/views', 0777, true);
    file_put_contents($root . '/resources/views/greeting.blade.php', "<p>Olá, {{ \$name }}</p>\n@if (\$loud)\n    <strong>!</strong>\n@endif\n");
    file_put_contents($root . '/tests/GreetingTest.php', <<<'GREETING'
    <?php

    declare(strict_types=1);

    namespace Fixture\Project\Tests;

    use Illuminate\Container\Container;
    use Illuminate\Filesystem\Filesystem;
    use Illuminate\View\Compilers\BladeCompiler;
    use PHPUnit\Framework\TestCase;

    final class GreetingTest extends TestCase
    {
        protected function setUp(): void
        {
            $token = getenv('TEST_TOKEN');
            $compiled = (getenv('VIEW_COMPILED_PATH') ?: sys_get_temp_dir() . '/greeting-' . getmypid()) . ($token === false ? '' : '/test_' . $token);
            $container = new Container();
            $container->instance('blade.compiler', new BladeCompiler(new Filesystem(), $compiled));
            Container::setInstance($container);
        }

        public function testGreets(): void
        {
            $compiler = Container::getInstance()->make('blade.compiler');
            $view = dirname(__DIR__) . '/resources/views/greeting.blade.php';

            if ($compiler->isExpired($view)) {
                $compiler->compile($view);
            }

            $name = 'Sonda';
            $loud = false;
            ob_start();
            include $compiler->getCompiledPath($view);

            self::assertSame("<p>Olá, Sonda</p>\n", ob_get_clean());
        }
    }
    GREETING);

    return $root;
}

/** The project with a helpers file in Composer's `files` autoload, and a test that uses it. */
function helpersProject(): string
{
    $root = sondaProject();
    mkdir($root . '/vendor/composer');
    file_put_contents($root . '/src/helpers.php', "<?php\n\nfunction fixture_discounted(int \$price, bool \$member): int\n{\n    if (\$member) {\n        return \$price - 1;\n    }\n\n    return \$price;\n}\n");
    file_put_contents($root . '/vendor/composer/autoload_files.php', "<?php\n\nreturn ['fixture-helpers' => dirname(__DIR__, 2) . '/src/helpers.php'];\n");
    file_put_contents($root . '/tests/HelpersTest.php', <<<'HELPERS'
    <?php

    declare(strict_types=1);

    namespace Fixture\Project\Tests;

    use PHPUnit\Framework\TestCase;

    final class HelpersTest extends TestCase
    {
        public function testMemberDiscount(): void
        {
            self::assertSame(9, fixture_discounted(10, true));
        }
    }
    HELPERS);

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

it('runs several test paths in parallel, through a configuration generated for them', function (): void {
    $root = sondaProject();

    [$status, $output] = sonda($root, 'test', 'tests/DiscountTest.php', 'tests/ShippingTest.php');

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('Processes:')
        ->toContain('OK (2 tests, 3 assertions)')
        ->toContain('Cobertura: linhas 3/5 (60,00%), branches 4/7 (57,14%)')
        ->and(glob($root . '/.sonda-*'))->toBe([]);
});

it('measures a project with plain PHPUnit when asked not to run in parallel', function (): void {
    [$status, $output] = sonda(sondaProject(), 'test', '--no-parallel', '--html=build/html');

    expect($status)->toBe(0)
        ->and($output)->toContain('Cobertura: linhas 3/5 (60,00%), branches 4/7 (57,14%)')
        ->toContain('Relatório HTML em ');
});

it('measures the Blade views of a Laravel project, in the subdirectory per worker of its parallel testing', function (): void {
    [$status, $output] = sonda(laravelLikeProject(), 'test');

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('OK (3 tests, 4 assertions)')
        ->toContain("\nCobertura: linhas 5/8 (62,50%), branches 5/9 (55,56%)\n");
});

it('reports the Blade views next to the source, with plain PHPUnit', function (): void {
    $root = laravelLikeProject();

    [$status, $output] = sonda($root, 'test', '--no-parallel', '--html=build/html');

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('Cobertura: linhas 5/8 (62,50%), branches 5/9 (55,56%)')
        ->and($root . '/build/html/resources/views/greeting.blade.php.html')->toBeFile()
        ->and($root . '/build/html/src/Discount.php.html')->toBeFile();
});

it('leaves the Blade views out when asked', function (): void {
    $root = laravelLikeProject();

    [$status, $output] = sonda($root, 'test', '--no-blade', '--html=build/html');

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('OK (3 tests, 4 assertions)')
        ->toContain('Cobertura: linhas 3/5 (60,00%), branches 4/7 (57,14%)')
        ->and(is_dir($root . '/build/html/resources'))->toBeFalse();
});

it("measures the helpers of Composer's files autoload, in parallel and in series", function (): void {
    [$status, $output] = sonda(helpersProject(), 'test');
    [$seriesStatus, $seriesOutput] = sonda(helpersProject(), 'test', '--no-parallel');

    expect($status)->toBe(0, $output)
        ->and($output)->toContain('OK (3 tests, 4 assertions)')
        ->toContain("\nCobertura: linhas 5/8 (62,50%), branches 6/10 (60,00%)\n")
        ->and($seriesStatus)->toBe(0, $seriesOutput)
        ->and($seriesOutput)->toContain('Cobertura: linhas 5/8 (62,50%), branches 6/10 (60,00%)');
});

it('fails on the uncovered parts of the methods changed since a reference', function (): void {
    $root = sondaProject();
    commitEverything($root);
    file_put_contents($root . '/src/Shipping.php', str_replace('? 30 : 10;', '? 30 : 10; // changed', (string) file_get_contents($root . '/src/Shipping.php')));

    [$status, $output] = sonda($root, 'test', '--changed=master');

    expect($status)->toBe(1)
        ->and($output)->toContain("Cobertura: linhas 1/1 (100,00%), branches 2/3 (66,67%)\nDo que mudou: linhas 1/1 (100,00%), branches 2/3 (66,67%)\nSem cobertura:\nsrc/Shipping.php: branches 11\n");
});

it('finds the changes of a project in a subdirectory of the repository', function (): void {
    $repository = TemporaryDirectory::create();
    $root = $repository . '/project';
    rename(sondaProject(), $root);
    commitEverything($repository);
    file_put_contents($root . '/src/Shipping.php', str_replace('? 30 : 10;', '? 30 : 10; // changed', (string) file_get_contents($root . '/src/Shipping.php')));

    [$status, $output] = sonda($root, 'test', '--changed=master');

    expect($status)->toBe(1)
        ->and($output)->toContain("Do que mudou: linhas 1/1 (100,00%), branches 2/3 (66,67%)\nSem cobertura:\nsrc/Shipping.php: branches 11\n");
});

it('passes a change that touches no method, whatever the file leaves uncovered', function (): void {
    $root = sondaProject();
    commitEverything($root);
    file_put_contents($root . '/src/Shipping.php', str_replace('final class', "/** changed */\nfinal class", (string) file_get_contents($root . '/src/Shipping.php')));

    [$status, $output] = sonda($root, 'test', '--changed=master');

    expect($status)->toBe(0)
        ->and($output)->toContain("Cobertura: linhas 1/1 (100,00%), branches 2/3 (66,67%)\nDo que mudou: linhas 0/0 (100,00%), branches 0/0 (100,00%)\nO que mudou está 100% coberto.\n");
});

it('checks a new file whole', function (): void {
    $root = sondaProject();
    commitEverything($root);
    file_put_contents($root . '/src/Fresh.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture\\Project;\n\nfinal class Fresh\n{\n    public static function nothing(): int\n    {\n        return 1;\n    }\n}\n");

    [$status, $output] = sonda($root, 'test', '--changed=master');

    expect($status)->toBe(1)
        ->and($output)->toContain("Cobertura: linhas 0/1 (0,00%), branches 0/0 (100,00%)\nDo que mudou: linhas 0/1 (0,00%), branches 0/0 (100,00%)\nSem cobertura:\nsrc/Fresh.php: linhas 11\n");
});

it('checks the files named explicitly whole, even when they also changed', function (): void {
    $root = sondaProject();
    commitEverything($root);
    file_put_contents($root . '/src/Shipping.php', str_replace('final class', "/** changed */\nfinal class", (string) file_get_contents($root . '/src/Shipping.php')));

    [$status, $output] = sonda($root, 'test', '--changed=master', '--files=src/Shipping.php');

    expect($status)->toBe(1)
        ->and($output)->toContain("Cobertura: linhas 1/1 (100,00%), branches 2/3 (66,67%)\nDo que mudou: linhas 1/1 (100,00%), branches 2/3 (66,67%)\nSem cobertura:\nsrc/Shipping.php: branches 12\n");
});

it('checks the files named explicitly whole, without a change summary', function (): void {
    [$status, $output] = sonda(sondaProject(), 'test', '--files=src/Shipping.php');

    expect($status)->toBe(1)
        ->and($output)->toContain("Cobertura: linhas 1/1 (100,00%), branches 2/3 (66,67%)\nSem cobertura:\nsrc/Shipping.php: branches 11\n")
        ->not->toContain('Do que mudou');
});

it('measures the whole project, views included, when nothing changed in the source but a floor was asked for', function (): void {
    $root = laravelLikeProject();
    commitEverything($root);
    file_put_contents($root . '/src/Ignored/Legacy.php', "<?php\n// changed\n");

    [$status, $output] = sonda($root, 'test', '--changed=master', '--min-lines=50', '--cobertura=build/cobertura.xml');

    expect($status)->toBe(0, $output)
        ->and($output)->toStartWith("Nenhum arquivo alterado dentro de <source>: com piso de cobertura, o projeto inteiro é medido.\n")
        ->toContain('Cobertura: linhas 5/8 (62,50%), branches 5/9 (55,56%)')
        ->and($root . '/build/cobertura.xml')->toBeFile();
});

it('writes the reports asked for even when nothing changed in the source', function (): void {
    $root = sondaProject();
    commitEverything($root);
    file_put_contents($root . '/src/Ignored/Legacy.php', "<?php\n// changed\n");

    [$status, $output] = sonda($root, 'test', '--changed=master', '--cobertura=build/cobertura.xml');

    expect($status)->toBe(0, $output)
        ->and($output)->toBe("Nenhum arquivo alterado dentro de <source>: nada a medir.\nRelatório Cobertura em {$root}/build/cobertura.xml\n")
        ->and((string) file_get_contents($root . '/build/cobertura.xml'))->toContain('lines-valid="0"');
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

it('names the tests that ran each covered line when asked, in parallel and in series', function (string ...$runner): void {
    $root = sondaProject();

    [$status, $output] = sonda($root, 'test', '--covered-by', '--cov=coverage.cov', ...$runner);
    $coverage = require $root . '/coverage.cov';
    $coveredBy = [];

    foreach ($coverage->getData()->lineCoverage() as $file => $lines) {
        $coveredBy[basename($file)] = array_filter($lines, static fn (?array $tests): bool => $tests !== null && $tests !== []);
    }

    $discount = DiscountTest::class . '::testLargeOrdersGetTheMaximumRate#0';
    $shipping = ShippingTest::class . '::testShortDistancesCostTheMinimum';

    expect($status)->toBe(0, $output)
        ->and($coveredBy)->toBe([
            'Discount.php' => [11 => [$discount], 12 => [$discount]],
            'Shipping.php' => [11 => [$shipping]],
        ])
        ->and(array_keys($coverage->getTests()))->toEqualCanonicalizing([$discount, $shipping])
        ->and($coverage->getTests()[$discount]['status'])->toBe('success');
})->with([
    'in parallel' => [],
    'in series' => ['--no-parallel'],
]);

/** The fixture project committed, with Sonda's cache, kept inside it by the tests, ignored. */
function committedSondaProject(): string
{
    $root = sondaProject();
    file_put_contents($root . '/.gitignore', ".cache/\n");
    commitEverything($root);

    return $root;
}

it('runs only the tests of the changed code once a run has mapped them', function (): void {
    $root = committedSondaProject();
    file_put_contents($root . '/src/Discount.php', str_replace('final class', "/** changed */\nfinal class", (string) file_get_contents($root . '/src/Discount.php')));

    [$firstStatus, $first] = sonda($root, 'test', '--changed=master', '--affected');
    [$secondStatus, $second] = sonda($root, 'test', '--changed=master', '--affected', '--no-parallel');
    [$thirdStatus, $third] = sonda($root, 'test', '--files=src/Shipping.php', '--affected');

    expect($firstStatus)->toBe(0, $first)
        ->and($first)->toStartWith("Rodando a suíte inteira e medindo o projeto todo: ainda não há mapa de testes deste projeto; esta execução o cria.\n")
        ->toContain('OK (2 tests, 3 assertions)')
        ->and($secondStatus)->toBe(0, $second)
        ->and($second)->toStartWith("Rodando só os testes afetados: tests/DiscountTest.php\n")
        ->toContain('OK (1 test, 2 assertions)')
        ->and($thirdStatus)->toBe(1, $third)
        ->and($third)->toStartWith("Rodando só os testes afetados: tests/ShippingTest.php\n")
        ->toContain('OK (1 test, 1 assertion)');
});

it('runs the whole suite when a change reaches beyond the source and the tests', function (): void {
    $root = committedSondaProject();
    sonda($root, 'test');
    file_put_contents($root . '/src/Shipping.php', str_replace('final class', "/** changed */\nfinal class", (string) file_get_contents($root . '/src/Shipping.php')));
    file_put_contents($root . '/routes.php', "<?php\n");

    [$status, $output] = sonda($root, 'test', '--changed=master', '--affected');

    expect($status)->toBe(0, $output)
        ->and($output)->toStartWith("Rodando a suíte inteira e medindo o projeto todo: {$root}/routes.php mudou e não é um arquivo do <source> nem um teste.\n")
        ->toContain('OK (2 tests, 3 assertions)');
});

it('leaves --affected aside when a floor asks for the whole suite or the tests to run were given', function (): void {
    $root = committedSondaProject();
    file_put_contents($root . '/src/Shipping.php', str_replace('final class', "/** changed */\nfinal class", (string) file_get_contents($root . '/src/Shipping.php')));

    [, $withFloor] = sonda($root, 'test', '--changed=master', '--affected', '--min-lines=1');
    [, $withPath] = sonda($root, 'test', '--changed=master', '--affected', 'tests/DiscountTest.php');

    expect($withFloor)->toStartWith("--affected ignorado: com piso de cobertura, a suíte inteira roda.\n")
        ->toContain('OK (2 tests, 3 assertions)')
        ->and($withPath)->toStartWith("--affected ignorado: os testes a rodar já foram dados.\n")
        ->toContain('OK (1 test, 2 assertions)');
});
