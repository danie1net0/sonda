<?php

declare(strict_types=1);

use CovProto\Hits;
use CovProto\Instrumenter;
use CovProto\Probe;

function writeSource(string $code): string
{
    $path = TemporaryDirectory::create() . '/Source.php';
    file_put_contents($path, $code);

    return $path;
}

/** Instruments a file that returns a closure, includes it and returns the closure. */
function instrumentedClosure(string $code, Instrumenter $instrumenter): Closure
{
    $result = $instrumenter->instrument(writeSource($code), 'file');
    $path   = TemporaryDirectory::create() . '/Instrumented.php';
    file_put_contents($path, $result->code);

    return require $path;
}

it('instruments a file and keeps its line count', function (): void {
    $code   = "<?php\n\$total = 1;\nif (\$total > 0) {\n    \$total++;\n}\n";
    $result = (new Instrumenter)->instrument(writeSource($code), 'file');

    expect($result->code)->toBe(
        "<?php\n\\CovProto\\Hits::\$current['file'][0]=1;\$total = 1;\n"
        . "\\CovProto\\Hits::\$current['file'][1]=1;if (\$total > 0) {\n"
        . "    \\CovProto\\Hits::\$current['file'][2]=1;\$total++;\n}\n",
    )
        ->and(substr_count($result->code, "\n"))->toBe(substr_count($code, "\n"))
        ->and($result->probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
            2 => new Probe(4, true, '{main}', 4, 4),
        ]);
});

it('replaces __FILE__ and __DIR__ with the path of the original file', function (): void {
    $path   = writeSource("<?php\nreturn [__FILE__, __DIR__];\n");
    $result = (new Instrumenter)->instrument($path, 'file');

    expect($result->code)->toContain(var_export($path, true) . ', ' . var_export(dirname($path), true));
});

it('instruments an empty file', function (): void {
    expect((new Instrumenter)->instrument(writeSource(''), 'file'))
        ->code->toBe('')
        ->probes->toBe([]);
});

it('records the outcomes taken when the instrumented code runs', function (): void {
    $classify = instrumentedClosure(<<<'PHP'
        <?php
        return function (int $value): string {
            if ($value > 0) {
                return 'positive';
            }

            return $value < 0 ? 'negative' : 'zero';
        };
        PHP, new Instrumenter);

    expect($classify(5))->toBe('positive')
        ->and(array_keys(Hits::$current['file']))->toBe([0, 1, 3]);

    expect($classify(-1))->toBe('negative')
        ->and($classify(0))->toBe('zero')
        ->and(array_keys(Hits::$current['file']))->toBe([0, 1, 3, 2, 4, 5]);
});

it('keeps the behavior of the code in strict and fast modes', function (): void {
    $code = <<<'PHP'
        <?php
        return function (array $items, ?int $limit): int {
            $sum = 0;
            foreach ($items as $item) {
                if ($item > 10) $sum += 10;
                $sum += $item ?: 1;
            }
            $sum = $limit ?? $sum;
            $sum ??= 0;

            return match (true) {
                $sum > 100 => 100,
                default => $sum,
            } + (fn (int $extra): int => $extra * 2)(1);
        };
        PHP;
    $plain        = require writeSource($code);
    $instrumented = instrumentedClosure($code, new Instrumenter(strict: true, fast: true));

    foreach ([[[1, 0, 20], null], [[], 7], [[500], null]] as [$items, $limit]) {
        expect($instrumented($items, $limit))->toBe($plain($items, $limit));
    }

    expect(Hits::$current['file'])->not->toBeEmpty();
});

it('fingerprints the code of its sources and its mode', function (): void {
    $contents = implode('', array_map('file_get_contents', Instrumenter::sources()));

    expect((new Instrumenter)->fingerprint())->toBe(sha1($contents . '00'))
        ->and((new Instrumenter(strict: true, fast: true))->fingerprint())->toBe(sha1($contents . '11'));
});

it('takes as sources the files that decide its output', function (): void {
    $directory = dirname(__DIR__, 2) . '/src';

    expect(Instrumenter::sources())->toBe([
        $directory . '/Instrumenter.php',
        $directory . '/InstrumentingVisitor.php',
        $directory . '/SourceEditor.php',
        $directory . '/Edit.php',
        $directory . '/Probe.php',
    ]);
});

it('changes its fingerprint with the mode and keeps it otherwise', function (): void {
    $fingerprints = [
        (new Instrumenter)->fingerprint(),
        (new Instrumenter(strict: true))->fingerprint(),
        (new Instrumenter(fast: true))->fingerprint(),
        (new Instrumenter(strict: true, fast: true))->fingerprint(),
    ];

    expect(array_unique($fingerprints))->toHaveCount(4)
        ->and((new Instrumenter)->fingerprint())->toBe($fingerprints[0])
        ->and($fingerprints[0])->toMatch('/^[0-9a-f]{40}$/');
});

it('renders an instrumented template exactly as the original', function (): void {
    $template = <<<'PHP'
        <ul>
        <?php if ($items !== []) { ?>
            <?php foreach ($items as $item): ?>
                <li><?= $item ?></li>
            <?php endforeach ?>
        <?php } ?>
        <?php if ($footer): ?><p>end</p><?php endif ?>
        </ul>
        PHP;
    $render = static function (string $path, array $items, bool $footer): string {
        ob_start();
        require $path;

        return (string) ob_get_clean();
    };
    $original     = writeSource($template);
    $instrumented = TemporaryDirectory::create() . '/template.php';
    file_put_contents($instrumented, (new Instrumenter(strict: true))->instrument($original, 'file')->code);

    foreach ([[['a', 'b'], true], [[], false]] as [$items, $footer]) {
        expect($render($instrumented, $items, $footer))->toBe($render($original, $items, $footer));
    }

    expect(Hits::$current['file'])->not->toBeEmpty();
});

it('keeps the behavior of ?-> chains, also with undefined values in silent contexts', function (): void {
    $code = <<<'PHP'
        <?php
        return function (?object $order, array $data): array {
            $results   = [];
            $results[] = $order?->customer?->name;
            $results[] = $order?->customer->name;
            $results[] = isset($data['user']?->name);
            $results[] = empty($data['user']?->name);
            $results[] = $data['user']?->name ?? 'none';
            $results[] = $order?->total();

            return $results;
        };
        PHP;
    $order = new class {
        public object $customer;

        public function __construct()
        {
            $this->customer = (object) ['name' => 'Ana'];
        }

        public function total(): int
        {
            return 10;
        }
    };
    $plain = require writeSource($code);

    foreach ([true, false] as $strict) {
        $instrumented = instrumentedClosure($code, new Instrumenter(strict: $strict, fast: true));

        foreach ([[$order, ['user' => $order->customer]], [null, []]] as [$value, $data]) {
            expect($instrumented($value, $data))->toBe($plain($value, $data));
        }
    }

    expect(Hits::$current['file'])->not->toBeEmpty();
});

it('keeps undefined keys on the left of ?? silent when that side has a line of its own', function (): void {
    $code = <<<'PHP'
        <?php
        return function (array $overrides, array $defaults): string {
            return $overrides['tone']
                ?? $defaults['tone']
                ?? 'neutral';
        };
        PHP;
    $plain        = require writeSource($code);
    $instrumented = instrumentedClosure($code, new Instrumenter(strict: true, fast: true));

    foreach ([[[], []], [[], ['tone' => 'formal']], [['tone' => 'casual'], []]] as [$overrides, $defaults]) {
        expect($instrumented($overrides, $defaults))->toBe($plain($overrides, $defaults));
    }
});
