<?php

declare(strict_types=1);

use Sonda\{InstrumentationCache, InstrumentationResult, Probe, Run};
use Sonda\Git\Git;
use Sonda\Cli\{ProcessRunner, TestCommand, TestOptions};

/**
 * Stands in for the test runner: records the call and, like the bootstrap would, saves a run
 * of the project's Order.php in SONDA_OUT.
 */
final class FakeRunner implements ProcessRunner
{
    /** @var list<string> */
    public array $command = [];

    /** @var array<string, string> */
    public array $environment = [];

    public string $directory = '';

    public int $calls = 0;

    /** What the generated configuration held, read while it still existed. */
    public string $generatedConfiguration = '';

    /** @param array<int, int> $hits probes of Order.php to report as hit */
    public function __construct(private readonly int $status = 0, private readonly array $hits = [0 => 1, 1 => 1, 2 => 1], private readonly bool $savesRun = true)
    {
    }

    public function run(array $command, array $environment, string $directory): int
    {
        foreach ($command as $argument) {
            if (str_starts_with($argument, '--configuration=' . $directory . '/.sonda-')) {
                $this->generatedConfiguration = (string) file_get_contents(substr($argument, strlen('--configuration=')));
            }
        }

        $this->command = $command;
        $this->environment = $environment;
        $this->directory = $directory;
        $this->calls++;

        if ($this->savesRun) {
            $order = $directory . '/app/Order.php';
            $probes = [0 => new Probe(7, true, 'Order->total', 7, 11), 1 => new Probe(8, true, 'Order->total', 8, 8), 2 => new Probe(11, false, 'Order->total', 0, 0)];
            new InstrumentationCache($environment['SONDA_CACHE'])->store('order', new InstrumentationResult('<?php', $probes));
            new Run($environment['SONDA_CACHE'], ['o' => [$order, 'order']], ['o' => $this->hits])->saveIn($environment['SONDA_OUT']);
        }

        return $this->status;
    }
}

/** A Laravel-less project: vendor/bin/phpunit, phpunit.xml with <source>, app/Order.php and app/Providers. */
function fakeProject(string $configuration = ''): string
{
    $root = TemporaryDirectory::create();
    mkdir($root . '/vendor/bin', 0777, true);
    mkdir($root . '/app/Providers', 0777, true);
    file_put_contents($root . '/vendor/autoload.php', '<?php');
    file_put_contents($root . '/vendor/bin/phpunit', '');
    file_put_contents($root . '/app/Order.php', "<?php\n\nfinal class Order\n{\n    public function total(int \$quantity): int\n    {\n        if (\$quantity > 10) {\n            return 10;\n        }\n\n        return \$quantity;\n    }\n}\n");
    file_put_contents($root . '/app/Providers/AppProvider.php', "<?php\n\nfinal class AppProvider\n{\n}\n");
    file_put_contents($root . '/phpunit.xml', $configuration !== '' ? $configuration : <<<'XML'
    <phpunit bootstrap="vendor/autoload.php">
        <source>
            <include><directory>app</directory></include>
            <exclude><directory>app/Providers</directory></exclude>
        </source>
    </phpunit>
    XML);

    return $root;
}

/** @return array{0: int, 1: string} the exit code and the output */
function runTestCommand(FakeRunner $runner, string $root, TestOptions $options, string $temporaryRoot): array
{
    $output = fopen('php://memory', 'w+');

    if ($output === false) {
        throw new RuntimeException('Sem memória para a saída.');
    }

    $status = new TestCommand($runner, '/opt/sonda', $temporaryRoot . '/sonda')->run($options, $root, $output);
    rewind($output);

    return [$status, (string) stream_get_contents($output)];
}

it('runs the project tests instrumented and reports the coverage of its source', function (): void {
    $root = fakeProject();
    $temporary = TemporaryDirectory::create();
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--filter=Order']), $temporary);

    $work = $temporary . '/sonda/' . substr(sha1($root), 0, 12);

    expect($status)->toBe(0)
        ->and($runner->command)->toBe([PHP_BINARY, 'vendor/bin/phpunit', '--no-coverage', '--bootstrap=/opt/sonda/bootstrap.php', '--filter=Order'])
        ->and($runner->directory)->toBe($root)
        ->and($runner->environment)->toBe([
            'SONDA_AUTOLOAD' => $root . '/vendor/autoload.php',
            'SONDA_TARGET' => $root . '/app',
            'SONDA_VIEWS' => '',
            'SONDA_EXCLUDE' => $root . '/app/Providers',
            'SONDA_ONLY' => '',
            'SONDA_CACHE' => $work . '/cache',
            'SONDA_OUT' => $runner->environment['SONDA_OUT'],
            'SONDA_STRICT' => '1',
            'SONDA_FAST' => '1',
            'SONDA_CHAIN' => '',
        ])
        ->and($runner->environment['SONDA_OUT'])->toMatch('#^' . preg_quote($work . '/runs-', '#') . '[0-9a-f]{8}$#')
        ->and(is_dir($runner->environment['SONDA_OUT']))->toBeFalse()
        ->and(fileperms($temporary . '/sonda') & 0o777)->toBe(0o700)
        ->and(fileperms($work) & 0o777)->toBe(0o700)
        ->and($output)->toBe("\nCobertura: linhas 3/3 (100,00%), branches 2/2 (100,00%)\n");
});

it('chains the project bootstrap when it is not the autoload, and honours the flags', function (): void {
    $root = fakeProject('<phpunit bootstrap="tests/bootstrap.php"><source><include><directory>app</directory></include></source></phpunit>');
    mkdir($root . '/tests');
    file_put_contents($root . '/tests/bootstrap.php', '<?php');
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse(['--no-strict', '--no-fast', '--no-parallel']), TemporaryDirectory::create());

    expect($runner->environment)
        ->toHaveKey('SONDA_CHAIN', $root . '/tests/bootstrap.php')
        ->toHaveKey('SONDA_STRICT', '0')
        ->toHaveKey('SONDA_FAST', '0')
        ->toHaveKey('SONDA_EXCLUDE', '');
});

it('uses the configuration file given, relative to the project', function (): void {
    $root = fakeProject();
    file_put_contents($root . '/phpunit.ci.xml', '<phpunit><source><include><directory>app/Providers</directory></include></source></phpunit>');
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse(['-c', 'phpunit.ci.xml']), TemporaryDirectory::create());

    expect($runner->environment)->toHaveKey('SONDA_TARGET', $root . '/app/Providers')
        ->and($runner->command)->toContain('-c', 'phpunit.ci.xml');
});

it('measures only the given files and fails on what they leave uncovered', function (): void {
    $root = fakeProject();
    $runner = new FakeRunner(hits: [0 => 1, 2 => 1]);

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--files=app/Order.php']), TemporaryDirectory::create());

    expect($status)->toBe(1)
        ->and($runner->environment)->toHaveKey('SONDA_ONLY', $root . '/app/Order.php')
        ->and($output)->toBe("\nCobertura: linhas 2/3 (66,67%), branches 1/2 (50,00%)\nSem cobertura:\napp/Order.php: linhas 8; branches 8\n");
});

it('passes when the given files are fully covered', function (): void {
    $root = fakeProject();

    [$status, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--files=' . $root . '/app/Order.php']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->toEndWith("Os arquivos alterados estão 100% cobertos.\n");
});

it('runs nothing when none of the given files is in the source', function (): void {
    $root = fakeProject();
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--files=app/Providers/AppProvider.php,lib/Other.php']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($runner->calls)->toBe(0)
        ->and($output)->toBe("Nenhum arquivo alterado dentro de <source>: nada a medir.\n");
});

it('keeps the exit code of a failing test run', function (): void {
    $root = fakeProject();

    [$status] = runTestCommand(new FakeRunner(status: 2), $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($status)->toBe(2);
});

it('keeps the exit code of a failing test run over uncovered files', function (): void {
    $root = fakeProject();

    [$status, $output] = runTestCommand(new FakeRunner(status: 2, hits: []), $root, TestOptions::parse(['--files=app/Order.php']), TemporaryDirectory::create());

    expect($status)->toBe(2)
        ->and($output)->toContain('Sem cobertura:');
});

it('fails when the run left no coverage data', function (): void {
    $root = fakeProject();

    [$status, $output] = runTestCommand(new FakeRunner(savesRun: false), $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($status)->toBe(1)
        ->and($output)->toStartWith("\nSem dados de cobertura: O diretório ");

    [$status] = runTestCommand(new FakeRunner(status: 3, savesRun: false), $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($status)->toBe(3);
});

it('writes the reports asked for', function (): void {
    $root = fakeProject();

    [, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--html=build/html', '--clover=build/clover.xml', '--cov=' . $root . '/build/coverage.cov', '--cobertura=build/cobertura.xml']), TemporaryDirectory::create());

    expect(is_file($root . '/build/html/index.html'))->toBeTrue()
        ->and(is_file($root . '/build/clover.xml'))->toBeTrue()
        ->and(is_file($root . '/build/coverage.cov'))->toBeTrue()
        ->and((string) file_get_contents($root . '/build/cobertura.xml'))->toContain('<coverage ')
        ->and($output)->toContain("Relatório HTML em {$root}/build/html\n")
        ->toContain("Relatório Clover em {$root}/build/clover.xml\n")
        ->toContain("Relatório .cov em {$root}/build/coverage.cov\n")
        ->toContain("Relatório Cobertura em {$root}/build/cobertura.xml\n");
});

it('measures the files changed since a reference together with the ones given', function (): void {
    $root = fakeProject();
    file_put_contents($root . '/app/Kept.php', "<?php\n");
    Git::run($root, ['init', '-q', '-b', 'master']);
    Git::run($root, ['config', 'user.email', 'tests@example.com']);
    Git::run($root, ['config', 'user.name', 'Tests']);
    Git::run($root, ['add', '-A']);
    Git::run($root, ['commit', '-q', '-m', 'base']);
    file_put_contents($root . '/app/Order.php', "<?php\n\nfinal class Order\n{\n    public function total(int \$quantity): int\n    {\n        if (\$quantity > 10) {\n            return 10;\n        }\n\n        return \$quantity; // changed\n    }\n}\n");
    file_put_contents($root . '/app/Extra.php', "<?php\n");
    $runner = new FakeRunner(hits: [0 => 1, 2 => 1]);

    [$status] = runTestCommand($runner, $root, TestOptions::parse(['--changed=master', '--files=app/Kept.php,app/Providers/AppProvider.php']), TemporaryDirectory::create());

    expect($status)->toBe(1)
        ->and($runner->environment)->toHaveKey('SONDA_ONLY', implode(PATH_SEPARATOR, [$root . '/app/Extra.php', $root . '/app/Kept.php', $root . '/app/Order.php']));
});

/** The fake project under git, with the base commit done. */
function committedFakeProject(): string
{
    $root = fakeProject();
    Git::run($root, ['init', '-q', '-b', 'master']);
    Git::run($root, ['config', 'user.email', 'tests@example.com']);
    Git::run($root, ['config', 'user.name', 'Tests']);
    Git::run($root, ['add', '-A']);
    Git::run($root, ['commit', '-q', '-m', 'base']);

    return $root;
}

it('passes when what changed is fully covered, whatever else the file leaves uncovered', function (): void {
    $root = committedFakeProject();
    // The class line changes: outside every method, so line 8 staying uncovered does not matter.
    file_put_contents($root . '/app/Order.php', "<?php\n\nfinal class Order // changed\n{\n    public function total(int \$quantity): int\n    {\n        if (\$quantity > 10) {\n            return 10;\n        }\n\n        return \$quantity;\n    }\n}\n");
    $runner = new FakeRunner(hits: [0 => 1, 2 => 1]);

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--changed=master']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->toEndWith("Do que mudou: linhas 0/0 (100,00%), branches 0/0 (100,00%)\nO que mudou está 100% coberto.\n");
});

it('checks the whole method a change falls in and says how much of what changed is covered', function (): void {
    $root = committedFakeProject();
    file_put_contents($root . '/app/Order.php', "<?php\n\nfinal class Order\n{\n    public function total(int \$quantity): int\n    {\n        if (\$quantity > 10) {\n            return 10;\n        }\n\n        return \$quantity; // changed\n    }\n}\n");

    [$status, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--changed=master']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->toEndWith("Cobertura: linhas 3/3 (100,00%), branches 2/2 (100,00%)\nDo que mudou: linhas 3/3 (100,00%), branches 2/2 (100,00%)\nO que mudou está 100% coberto.\n");
});

it('checks a file named explicitly whole even when its change touches no method', function (): void {
    $root = committedFakeProject();
    file_put_contents($root . '/app/Order.php', "<?php\n\nfinal class Order // changed\n{\n    public function total(int \$quantity): int\n    {\n        if (\$quantity > 10) {\n            return 10;\n        }\n\n        return \$quantity;\n    }\n}\n");
    $runner = new FakeRunner(hits: [0 => 1, 2 => 1]);

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--changed=master', '--files=app/Order.php']), TemporaryDirectory::create());

    expect($status)->toBe(1)
        ->and($runner->environment)->toHaveKey('SONDA_ONLY', $root . '/app/Order.php')
        ->and($output)->toEndWith("Cobertura: linhas 2/3 (66,67%), branches 1/2 (50,00%)\nDo que mudou: linhas 2/3 (66,67%), branches 1/2 (50,00%)\nSem cobertura:\napp/Order.php: linhas 8; branches 8\n");
});

it('runs several test paths in parallel through a configuration generated beside the project one, and removes it', function (): void {
    $root = fakeProject();
    file_put_contents($root . '/vendor/bin/paratest', '');
    mkdir($root . '/tests/Unit', 0777, true);
    mkdir($root . '/tests/Feature');
    $one = new FakeRunner();
    $two = new FakeRunner();
    $configured = new FakeRunner();
    $series = new FakeRunner();

    runTestCommand($one, $root, TestOptions::parse(['tests/Unit']), TemporaryDirectory::create());
    runTestCommand($two, $root, TestOptions::parse(['--filter=x', 'tests/Unit', 'tests/Feature']), TemporaryDirectory::create());
    runTestCommand($configured, $root, TestOptions::parse(['-c', 'phpunit.xml', 'tests/Unit', 'tests/Feature']), TemporaryDirectory::create());
    runTestCommand($series, $root, TestOptions::parse(['--no-parallel', 'tests/Unit', 'tests/Feature']), TemporaryDirectory::create());

    $generated = array_values(array_filter($two->command, static fn (string $argument): bool => str_starts_with($argument, '--configuration=')));

    expect($one->command[1])->toBe('vendor/bin/paratest')
        ->and(array_slice($one->command, -1))->toBe(['tests/Unit'])
        ->and($two->command[1])->toBe('vendor/bin/paratest')
        ->and(array_slice($two->command, -2))->toEqual(['--filter=x', $generated[0] ?? ''])
        ->and($generated[0] ?? '')->toMatch('#^--configuration=' . preg_quote($root, '#') . '/\\.sonda-[0-9a-f]{8}\\.phpunit\\.xml$#')
        ->and($two->generatedConfiguration)->toContain('<directory suffix="Test.php">' . $root . '/tests/Unit</directory>')
        ->and(glob($root . '/.sonda-*'))->toBe([])
        ->and($configured->command[1])->toBe('vendor/bin/paratest')
        ->and(array_slice($configured->command, -1)[0])->toStartWith('--configuration=' . $root . '/.sonda-')
        ->and($series->command[1])->toBe('vendor/bin/phpunit')
        ->and(array_slice($series->command, -2))->toBe(['tests/Unit', 'tests/Feature']);
});

/** The fake project as a Laravel one: an artisan file and Blade views. */
function laravelProject(): string
{
    $root = fakeProject();
    mkdir($root . '/resources/views/mail', 0777, true);
    file_put_contents($root . '/artisan', '<?php');
    file_put_contents($root . '/resources/views/home.blade.php', "<h1>{{ \$title }}</h1>\n");
    file_put_contents($root . '/resources/views/mail/welcome.blade.php', "<p>{{ \$name }}</p>\n");
    file_put_contents($root . '/resources/views/plain.php', "<?php echo 'not blade';\n");

    return $root;
}

it('measures the Blade views of a Laravel project along with its source', function (): void {
    $root = laravelProject();
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($runner->environment)->toHaveKey('SONDA_VIEWS', $root . '/resources/views')
        ->toHaveKey('SONDA_TARGET', $root . '/app' . PATH_SEPARATOR . $root . '/resources/views')
        ->and($output)->toContain("Cobertura: linhas 3/3 (100,00%), branches 2/2 (100,00%)\n");
});

it('narrows to a Blade view named explicitly, and not to a plain PHP file under the views', function (): void {
    $root = laravelProject();
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse(['--files=resources/views/home.blade.php,resources/views/plain.php']), TemporaryDirectory::create());

    expect($runner->environment)->toHaveKey('SONDA_ONLY', $root . '/resources/views/home.blade.php');
});

it('measures the views of the packages and modules kept in the project, not of its dependencies', function (): void {
    $root = laravelProject();
    mkdir($root . '/packages/ui/resources/views', 0777, true);
    mkdir($root . '/vendor/acme/pkg/resources/views', 0777, true);
    file_put_contents($root . '/packages/ui/resources/views/button.blade.php', "<button>{{ \$label }}</button>\n");
    file_put_contents($root . '/vendor/acme/pkg/resources/views/x.blade.php', "<p>{{ \$x }}</p>\n");
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($runner->environment)->toHaveKey('SONDA_VIEWS', $root . '/packages/ui/resources/views' . PATH_SEPARATOR . $root . '/resources/views')
        ->toHaveKey('SONDA_TARGET', $root . '/app' . PATH_SEPARATOR . $root . '/packages/ui/resources/views' . PATH_SEPARATOR . $root . '/resources/views');
});

it('measures the views of the directories given with --views, relative to the project or absolute, each once', function (): void {
    $root = fakeProject();
    mkdir($root . '/app/views', 0777, true);
    file_put_contents($root . '/app/views/page.blade.php', "<p>{{ \$x }}</p>\n");
    // A source file whose name starts like the views directory is still a source file.
    file_put_contents($root . '/app/views.php', "<?php\n\necho 1;\n");
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse(['--views=app/views,' . $root . '/app/views']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($runner->environment)->toHaveKey('SONDA_VIEWS', $root . '/app/views')
        ->toHaveKey('SONDA_TARGET', $root . '/app' . PATH_SEPARATOR . $root . '/app/views')
        ->and($output)->toContain('Cobertura: linhas 3/4 (75,00%)');
});

it('refuses a --views directory that does not exist', function (): void {
    $root = fakeProject();

    expect(fn (): array => runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--views=themes/none']), TemporaryDirectory::create()))
        ->toThrow(InvalidArgumentException::class, 'A pasta de views themes/none não existe.');
});

it('hands the workers real paths, even when the temporary root is reached through a symlink', function (): void {
    $root = laravelProject();
    $real = TemporaryDirectory::create();
    $link = TemporaryDirectory::create() . '/link';
    symlink($real, $link);
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse([]), $link);

    expect($runner->environment['SONDA_CACHE'])->toStartWith(realpath($real) . '/')
        ->and($runner->environment['SONDA_OUT'])->toStartWith(realpath($real) . '/');
});

it('leaves the views out with --no-blade', function (): void {
    $root = laravelProject();
    $runner = new FakeRunner();

    runTestCommand($runner, $root, TestOptions::parse(['--no-blade']), TemporaryDirectory::create());

    expect($runner->environment)->toHaveKey('SONDA_VIEWS', '')
        ->toHaveKey('SONDA_TARGET', $root . '/app');
});

it('refuses to work under a directory open to other users, before running anything', function (string $shared): void {
    $root = fakeProject();
    $temporary = TemporaryDirectory::create();
    $work = $temporary . '/sonda/' . substr(sha1($root), 0, 12);
    mkdir($temporary . '/sonda', $shared === 'root' ? 0o755 : 0o700);

    if ($shared === 'project') {
        mkdir($work, 0o755);
    }

    $runner = new FakeRunner();
    $refused = $shared === 'root' ? $temporary . '/sonda' : $work;

    expect(fn (): array => runTestCommand($runner, $root, TestOptions::parse([]), $temporary))
        ->toThrow(RuntimeException::class, "O diretório {$refused} precisa pertencer ao usuário atual e ser privado: chmod 700 nele resolve.")
        ->and($runner->calls)->toBe(0);
})->with(['root', 'project']);

it('lists the coverage of each file with --text', function (): void {
    $root = fakeProject();

    [$status, $output] = runTestCommand(new FakeRunner(), $root, TestOptions::parse(['--text']), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->toContain("\nCobertura: linhas 3/3 (100,00%), branches 2/2 (100,00%)\napp/Order.php: linhas 3/3 (100,00%), branches 2/2 (100,00%)\n");
});

it('fails below the floors of --min-lines and --min-branches, saying which, and passes at them', function (): void {
    $root = fakeProject();

    [$below, $belowOutput] = runTestCommand(new FakeRunner(hits: [0 => 1, 2 => 1]), $root, TestOptions::parse(['--min-lines=70', '--min-branches=50', '--html=build/html']), TemporaryDirectory::create());
    [$at] = runTestCommand(new FakeRunner(hits: [0 => 1, 2 => 1]), $root, TestOptions::parse(['--min-lines=66.66', '--min-branches=50']), TemporaryDirectory::create());
    [$failedRun] = runTestCommand(new FakeRunner(status: 2, hits: [0 => 1, 2 => 1]), $root, TestOptions::parse(['--min-lines=70']), TemporaryDirectory::create());

    expect($below)->toBe(1)
        ->and($belowOutput)->toContain("Cobertura abaixo do mínimo: linhas 66,67% < 70,00%\n")
        ->toContain('Relatório HTML em ')
        ->and($at)->toBe(0)
        ->and($failedRun)->toBe(2);
});

it('warns before the run about a file of the source that another one requires directly, which is not measured', function (): void {
    $root = fakeProject();
    file_put_contents($root . '/app/helpers.php', "<?php\n\nfunction fake_helper(): int\n{\n    return 1;\n}\n");
    file_put_contents($root . '/app/Boot.php', "<?php\n\nrequire __DIR__ . '/helpers.php';\n");
    $runner = new FakeRunner();

    [$status, $output] = runTestCommand($runner, $root, TestOptions::parse([]), TemporaryDirectory::create());

    expect($status)->toBe(0)
        ->and($output)->toStartWith("Aviso: app/helpers.php é carregado por require direto em app/Boot.php:3 e não é medido; só o autoloader instrumenta.\n")
        ->and($runner->calls)->toBe(1);
});

it("refuses to run when a file of Composer's files autoload is also required directly, which would declare it twice", function (): void {
    $root = fakeProject();
    mkdir($root . '/vendor/composer');
    file_put_contents($root . '/app/helpers.php', "<?php\n\nfunction fake_helper(): int\n{\n    return 1;\n}\n");
    file_put_contents($root . '/app/Boot.php', "<?php\n\nrequire_once __DIR__ . '/helpers.php';\n");
    file_put_contents($root . '/vendor/composer/autoload_files.php', "<?php\n\nreturn ['h' => dirname(__DIR__, 2) . '/app/helpers.php'];\n");
    $runner = new FakeRunner();

    expect(fn (): array => runTestCommand($runner, $root, TestOptions::parse([]), TemporaryDirectory::create()))
        ->toThrow(RuntimeException::class, 'app/helpers.php está na chave files do Composer e ainda é carregado por require direto em app/Boot.php:3: sob o Sonda seria declarado duas vezes. Tire o require.')
        ->and($runner->calls)->toBe(0);
});
