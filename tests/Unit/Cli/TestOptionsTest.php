<?php

declare(strict_types=1);

use Sonda\Cli\TestOptions;

it('measures everything, strict, fast and in parallel by default', function (): void {
    expect(TestOptions::parse([]))->toEqual(new TestOptions());
});

it('reads its own options and passes the rest to the runner', function (): void {
    $options = TestOptions::parse([
        '--changed=origin/master', '--files=app/A.php,app/B.php', '--files=app/C.php', '--views=themes/dark,themes/light', '--views=/views', '--html=build/html', '--clover=clover.xml', '--cov=coverage.cov', '--cobertura=cobertura.xml',
        '--no-strict', '--no-fast', '--no-parallel', '--no-blade', '--text', '--min-lines=90', '--min-branches=85,5', '--filter=Order', 'tests/Feature',
    ]);

    expect($options)->toEqual(new TestOptions(
        changed: 'origin/master',
        files: ['app/A.php', 'app/B.php', 'app/C.php'],
        strict: false,
        fast: false,
        parallel: false,
        blade: false,
        views: ['themes/dark', 'themes/light', '/views'],
        text: true,
        minLines: 90.0,
        minBranches: 85.5,
        html: 'build/html',
        clover: 'clover.xml',
        cov: 'coverage.cov',
        cobertura: 'cobertura.xml',
        runnerArguments: ['--filter=Order', 'tests/Feature'],
    ));
});

it('keeps the configuration file for itself and for the runner', function (): void {
    expect(TestOptions::parse(['-c', 'phpunit.ci.xml', '--filter=x']))
        ->configuration->toBe('phpunit.ci.xml')
        ->runnerArguments->toBe(['-c', 'phpunit.ci.xml', '--filter=x'])
        ->and(TestOptions::parse(['--configuration', 'a.xml']))
        ->configuration->toBe('a.xml')
        ->runnerArguments->toBe(['--configuration', 'a.xml'])
        ->and(TestOptions::parse(['--configuration=b.xml']))
        ->configuration->toBe('b.xml')
        ->runnerArguments->toBe(['--configuration=b.xml']);
});

it('passes everything after -- to the runner untouched', function (): void {
    expect(TestOptions::parse(['--no-fast', '--filter=x', '--', '--no-strict', '--changed=x']))
        ->fast->toBeFalse()
        ->strict->toBeTrue()
        ->changed->toBeNull()
        ->runnerArguments->toBe(['--filter=x', '--no-strict', '--changed=x']);
});

it('ignores empty entries in the file and view lists', function (): void {
    expect(TestOptions::parse(['--files=a.php,,b.php,']))->files->toBe(['a.php', 'b.php'])
        ->and(TestOptions::parse(['--views=,a,']))->views->toBe(['a']);
});

it('passes an option it knows only with a value to the runner when it comes bare', function (): void {
    expect(TestOptions::parse(['--changed', '--html']))
        ->changed->toBeNull()
        ->runnerArguments->toBe(['--changed', '--html']);
});

it('requires a value for the options that take one', function (string $argument, string $message): void {
    expect(fn (): TestOptions => TestOptions::parse([$argument]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    ['--changed=', 'A opção --changed precisa de um valor.'],
    ['--html=', 'A opção --html precisa de um valor.'],
    ['-c', 'A opção -c precisa de um arquivo.'],
]);

it('accepts the ends of the range for the floors', function (): void {
    expect(TestOptions::parse(['--min-lines=0', '--min-branches=100']))->minLines->toBe(0.0)
        ->and(TestOptions::parse(['--min-lines=0', '--min-branches=100']))->minBranches->toBe(100.0);
});

it('requires a percentage from 0 to 100 for the floors', function (string $argument, string $option): void {
    expect(fn (): TestOptions => TestOptions::parse([$argument]))
        ->toThrow(InvalidArgumentException::class, "A opção {$option} precisa de um número de 0 a 100.");
})->with([
    ['--min-lines=abc', '--min-lines'],
    ['--min-lines=101', '--min-lines'],
    ['--min-branches=-1', '--min-branches'],
]);
