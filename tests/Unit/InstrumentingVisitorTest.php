<?php

declare(strict_types=1);

use Sonda\Probe;

it('puts a probe before each statement', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $a = 1;
    $b = 2;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$a = 1;
    {p1}$b = 2;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
        ]);
});

it('skips declarations, use, const and comments', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    use Foo\Bar;
    use Foo\{Baz, Qux};
    const LIMIT = 1;
    // comment
    function helper(): void
    {
        echo 1;
    }
    class Order
    {
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    declare(strict_types=1);
    namespace App;
    use Foo\Bar;
    use Foo\{Baz, Qux};
    const LIMIT = 1;
    // comment
    function helper(): void
    {
        {p0}echo 1;
    }
    class Order
    {
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(10, true, 'App\\helper', 10, 10),
        ]);
});

it('probes inline HTML in a PHP tag of its own, as the echo it is', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php echo 1; ?>
    <p>html</p>
    <?php echo 2;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php {p0}echo 1; ?>
    <?php {p1} ?><p>html</p>
    <?php {p2}echo 2;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(1, false, '{main}', 0, 0),
            1 => new Probe(2, false, '{main}', 0, 0),
            2 => new Probe(3, false, '{main}', 0, 0),
        ]);
});

it('gives back the newline the closing tag of the probe eats at the top of a template', function (): void {
    [$unix] = instrumentCode("\n<p>x</p>\n<?php echo 1;\n");
    [$windows] = instrumentCode("\r\n<p>x</p>\r\n<?php echo 1;\r\n");
    [$plain] = instrumentCode("<p>x</p>\n<?php echo 1;\n");

    expect($unix)->toBe(expected("<?php {p0} ?>\n\n<p>x</p>\n<?php {p1}echo 1;\n"))
        ->and($windows)->toBe(expected("<?php {p0} ?>\r\n\r\n<p>x</p>\r\n<?php {p1}echo 1;\r\n"))
        ->and($plain)->toBe(expected("<?php {p0} ?><p>x</p>\n<?php {p1}echo 1;\n"));
});

it('stops at __halt_compiler', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    echo 1;
    __halt_compiler();
    raw
    PHP);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}echo 1;
    __halt_compiler();
    raw
    PHP))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
        ]);
});

it('names methods after their namespaced class and marks the entry branch', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    namespace App;

    final class Order
    {
        public function total(int $quantity = 1): int
        {
            $total = $quantity * 2;

            return $total;
        }
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    namespace App;

    final class Order
    {
        public function total(int $quantity = 1): int
        {
            {p0}$total = $quantity * 2;

            {p1}return $total;
        }
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(8, true, 'App\\Order->total', 8, 10),
            1 => new Probe(10, false, 'App\\Order->total', 0, 0),
        ]);
});

it('names functions of the global namespace block', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    namespace {
        function helper(): int
        {
            return 1;
        }
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    namespace {
        function helper(): int
        {
            {p0}return 1;
        }
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(5, true, 'helper', 5, 5),
        ]);
});

it('leaves abstract and interface methods alone', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    abstract class Shape
    {
        abstract public function area(): float;
    }
    interface Named
    {
        public function name(): string;
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    abstract class Shape
    {
        abstract public function area(): float;
    }
    interface Named
    {
        public function name(): string;
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
        ]);
});

it('names methods of anonymous classes', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $object = new class {
        public function run(): int
        {
            return 1;
        }
    };
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$object = new class {
        public function run(): int
        {
            {p1}return 1;
        }
    };
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(5, true, 'class@anonymous->run', 5, 5),
        ]);
});

it('probes the closing brace of empty and comment-only bodies', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    function nothing(): void
    {
    }
    function onlyComment(): void
    {
        // todo
    }
    $closure = function (): void {
    };
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    function nothing(): void
    {
    {p1}}
    function onlyComment(): void
    {
        // todo
    {p2}}
    {p0}$closure = function (): void {
    {p3}};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(9, false, '{main}', 0, 0),
            1 => new Probe(4, true, 'nothing', 4, 4),
            2 => new Probe(8, true, 'onlyComment', 8, 8),
            3 => new Probe(10, true, '{closure}', 10, 10),
        ]);
});

it('probes closures', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $double = function (int $value): int {
        return $value * 2;
    };
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$double = function (int $value): int {
        {p1}return $value * 2;
    };
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, true, '{closure}', 3, 3),
        ]);
});

it('wraps the body of arrow functions', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $double = fn (int $value): int => $value * 2;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$double = fn (int $value): int => {o1}$value * 2{c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(2, true, '{closure}', 2, 2, [2]),
        ]);
});

it('gives a multi-line arrow function every line but those of nested functions', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $build = fn (array $row): array => [
        'name' => $row['name'],
        'map' => fn ($x) =>
            $x,
        'total' => 1,
    ];
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$build = fn (array $row): array => {o1}[
        'name' => $row['name'],
        'map' => fn ($x) =>
            {o2}$x{c},
        'total' => 1,
    ]{c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(2, true, '{closure}', 2, 7, [2, 3, 4, 6, 7]),
            2 => new Probe(5, true, '{closure}', 5, 5, [5]),
        ]);
});

it('leaves by-reference arrow functions unwrapped', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $get = fn &(array &$items) => $items;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$get = fn &(array &$items) => $items;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
        ]);
});

it('leaves constant expressions untouched', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    #[Attribute(1 ?: 2)]
    class Config
    {
        public const LIMIT = 1 ?: 2;
        public int $size = 1 ?: 2;

        public function run(int $value = 1 ?: 2): void
        {
            static $cache = 1 ?: 2;
        }
    }
    enum Status: int
    {
        case Active = 1 << 1;
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    #[Attribute(1 ?: 2)]
    class Config
    {
        public const LIMIT = 1 ?: 2;
        public int $size = 1 ?: 2;

        public function run(int $value = 1 ?: 2): void
        {
            {p0}static $cache = 1 ?: 2;
        }
    }
    enum Status: int
    {
        case Active = 1 << 1;
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(10, true, 'Config->run', 10, 10),
        ]);
});

it('probes property hooks with a block body or an expression body, not one returning by reference', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    class Person
    {
        public string $name {
            get {
                return 'x';
            }
            set => strtoupper($value);
        }
        public array $items {
            &get => $this->items;
        }
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    class Person
    {
        public string $name {
            get {
                {p0}return 'x';
            }
            set => {o1}strtoupper($value){c};
        }
        public array $items {
            &get => $this->items;
        }
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(6, true, '{hook}', 6, 6),
            1 => new Probe(8, true, '{hook}', 8, 8, [8]),
        ]);
});

it('probes the statements of a declare block', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    declare(ticks=1) {
        echo 1;
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    declare(ticks=1) {
        {p0}echo 1;
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(3, false, '{main}', 0, 0),
        ]);
});

it('marks each if, elseif and else body as a branch and probes the elseif condition', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    function grade(int $score): string
    {
        if ($score > 8) {
            return 'A';
        } elseif ($score > 5) {
            return 'B';
        } else {
            return 'C';
        }
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    function grade(int $score): string
    {
        {p0}if ($score > 8) {
            {p1}return 'A';
        } elseif ({o2}$score > 5{c}) {
            {p3}return 'B';
        } else {
            {p4}return 'C';
        }
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(4, true, 'grade', 4, 10),
            1 => new Probe(5, true, 'grade', 5, 5),
            2 => new Probe(6, false, 'grade', 6, 6),
            3 => new Probe(7, true, 'grade', 7, 7),
            4 => new Probe(9, true, 'grade', 9, 9),
        ]);
});

it('adds braces to unbraced bodies', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if ($a) echo 1;
    else if ($b) echo 2;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if ($a) { {p1}echo 1; }
    else { {p2}if ($b) { {p3}echo 2; } }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(2, true, '{main}', 2, 2),
            2 => new Probe(3, true, '{main}', 3, 3),
            3 => new Probe(3, true, '{main}', 3, 3),
        ]);
});

it('keeps the alternative syntax', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if ($a):
        echo 1;
    elseif ($b):
        echo 2;
    else:
        echo 3;
    endif;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if ($a):
        {p1}echo 1;
    elseif ({o2}$b{c}):
        {p3}echo 2;
    else:
        {p4}echo 3;
    endif;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, true, '{main}', 3, 3),
            2 => new Probe(4, false, '{main}', 4, 4),
            3 => new Probe(5, true, '{main}', 5, 5),
            4 => new Probe(7, true, '{main}', 7, 7),
        ]);
});

it('probes empty bodies as outcomes without a line, in braces, in the alternative syntax and in a do', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if ($a) {
    } elseif ($b) {
    } else {
    }
    while ($a) {}
    for (;;) {}
    foreach ($a as $b) {}
    do {} while ($a);
    if ($a):
    elseif ($b):
    else:
    endif;
    while ($a): endwhile;
    for (;;): endfor;
    foreach ($a as $b): endforeach;
    if ($a);
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if ($a) {
    {p10}} elseif ({o11}$b{c}) {
    {p12}} else {
    {p13}}
    {p1}while ($a) {{p14}}
    {p2}for (;;) {{p15}}
    {p3}foreach ($a as $b) {{p16}}
    {p4}do {{p18}} while ({o17}$a{c});
    {p5}if ($a):{p19}
    elseif ({o20}$b{c}):{p21}
    else:{p22}
    endif;
    {p6}while ($a): {p23}endwhile;
    {p7}for (;;): {p24}endfor;
    {p8}foreach ($a as $b): {p25}endforeach;
    {p9}if ($a);
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(6, false, '{main}', 0, 0),
            2 => new Probe(7, false, '{main}', 0, 0),
            3 => new Probe(8, false, '{main}', 0, 0),
            4 => new Probe(9, false, '{main}', 0, 0),
            5 => new Probe(10, false, '{main}', 0, 0),
            6 => new Probe(14, false, '{main}', 0, 0),
            7 => new Probe(15, false, '{main}', 0, 0),
            8 => new Probe(16, false, '{main}', 0, 0),
            9 => new Probe(17, false, '{main}', 0, 0),
            10 => new Probe(null, true, '{main}', 3, 3),
            11 => new Probe(3, false, '{main}', 3, 3),
            12 => new Probe(null, true, '{main}', 4, 4),
            13 => new Probe(null, true, '{main}', 5, 5),
            14 => new Probe(null, true, '{main}', 6, 6),
            15 => new Probe(null, true, '{main}', 7, 7),
            16 => new Probe(null, true, '{main}', 8, 8),
            17 => new Probe(9, false, '{main}', 9, 9),
            18 => new Probe(null, true, '{main}', 9, 9),
            19 => new Probe(null, true, '{main}', 10, 10),
            20 => new Probe(11, false, '{main}', 11, 11),
            21 => new Probe(null, true, '{main}', 11, 11),
            22 => new Probe(null, true, '{main}', 12, 12),
            23 => new Probe(null, true, '{main}', 14, 14),
            24 => new Probe(null, true, '{main}', 15, 15),
            25 => new Probe(null, true, '{main}', 16, 16),
        ]);
});

it('adds an else to ifs without one in strict mode, alternative syntax included', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if ($a) {
        echo 1;
    }
    if ($b) echo 2;
    if ($c) {
        echo 3;
    } else {
        echo 4;
    }
    if (($d)):
        echo 5;
    endif;
    PHP . "\n", strict: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if ($a) {
        {p4}echo 1;
    } else { {p5} }
    {p1}if ($b) { {p6}echo 2; } else { {p7} }
    {p2}if ($c) {
        {p8}echo 3;
    } else {
        {p9}echo 4;
    }
    {p3}if (($d)):
        {p10}echo 5;
    else: {p11} endif;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(5, false, '{main}', 0, 0),
            2 => new Probe(6, false, '{main}', 0, 0),
            3 => new Probe(11, false, '{main}', 0, 0),
            4 => new Probe(3, true, '{main}', 3, 3),
            5 => new Probe(null, true, '{main}', 4, 4),
            6 => new Probe(5, true, '{main}', 5, 5),
            7 => new Probe(null, true, '{main}', 5, 5),
            8 => new Probe(7, true, '{main}', 7, 7),
            9 => new Probe(9, true, '{main}', 9, 9),
            10 => new Probe(12, true, '{main}', 12, 12),
            11 => new Probe(null, true, '{main}', 13, 13),
        ]);
});

it('nests the synthetic elses of unbraced ifs', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if ($a) if ($b) echo 1;
    PHP . "\n", strict: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if ($a) { {p1}if ($b) { {p3}echo 1; } else { {p4} } } else { {p2} }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(2, true, '{main}', 2, 2),
            2 => new Probe(null, true, '{main}', 2, 2),
            3 => new Probe(2, true, '{main}', 2, 2),
            4 => new Probe(null, true, '{main}', 2, 2),
        ]);
});

it('marks loop bodies as branches and probes the do-while condition', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    while ($a) {
        $a--;
    }
    for ($i = 0; $i < 3; $i++) echo $i;
    foreach ($items as $item) {
        echo $item;
    }
    do {
        $b--;
    } while ($b > 0);
    while ($c);
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}while ($a) {
        {p5}$a--;
    }
    {p1}for ($i = 0; $i < 3; $i++) { {p6}echo $i; }
    {p2}foreach ($items as $item) {
        {p7}echo $item;
    }
    {p3}do {
        {p9}$b--;
    } while ({o8}$b > 0{c});
    {p4}while ($c);
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(5, false, '{main}', 0, 0),
            2 => new Probe(6, false, '{main}', 0, 0),
            3 => new Probe(9, false, '{main}', 0, 0),
            4 => new Probe(12, false, '{main}', 0, 0),
            5 => new Probe(3, true, '{main}', 3, 3),
            6 => new Probe(5, true, '{main}', 5, 5),
            7 => new Probe(7, true, '{main}', 7, 7),
            8 => new Probe(11, false, '{main}', 11, 11),
            9 => new Probe(10, true, '{main}', 10, 10),
        ]);
});

it('probes case bodies and the line of non-constant cases', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP_WRAP'
    <?php
    switch ($value) {
        case 1:
        case self::TWO:
            echo 'low';
            break;
        case PHP_INT_MAX:
            echo 'max';
            break;
        case $other:
            echo 'other';
            break;
        default:
            echo 'default';
    }
    PHP_WRAP . "\n");

    expect($code)->toBe(expected(<<<'PHP_WRAP'
    <?php
    {p0}switch ($value) {
        case {o1}1{c}:
        case self::TWO:
            {p2}echo 'low';
            {p3}break;
        case PHP_INT_MAX:
            {p4}echo 'max';
            {p5}break;
        case {o6}$other{c}:
            {p7}echo 'other';
            {p8}break;
        default:
            {p9}echo 'default';
    }
    PHP_WRAP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 3, 3),
            2 => new Probe(5, true, '{main}', 5, 6),
            3 => new Probe(6, false, '{main}', 0, 0),
            4 => new Probe(8, true, '{main}', 8, 9),
            5 => new Probe(9, false, '{main}', 0, 0),
            6 => new Probe(10, false, '{main}', 10, 10),
            7 => new Probe(11, true, '{main}', 11, 12),
            8 => new Probe(12, false, '{main}', 0, 0),
            9 => new Probe(14, true, '{main}', 14, 14),
        ]);
});

it('probes catch lines, empty catches and finally', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    try {
        run();
    } catch (RuntimeException $exception) {
        report($exception);
    } catch (LogicException) {
        // ignored
    } catch (Error) {
    } finally {
        cleanup();
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}try {
        {p1}run();
    } catch (RuntimeException $exception) {
        {p2}{p3}report($exception);
    } catch (LogicException) {
        // ignored
    {p4}} catch (Error) {
    {p5}} finally {
        {p6}cleanup();
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
            2 => new Probe(5, true, '{main}', 5, 5),
            3 => new Probe(4, false, '{main}', 4, 6),
            4 => new Probe(6, true, '{main}', 6, 8),
            5 => new Probe(8, true, '{main}', 8, 9),
            6 => new Probe(10, true, '{main}', 10, 10),
        ]);
});

it('probes the statements of a standalone block', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    {
        echo 1;
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}{
        {p1}echo 1;
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
        ]);
});

it('wraps the right operand of short-circuit operators', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $a = $b && $c;
    $d = $e || $f;
    $g = $h and $i;
    $j = $k or $l;
    $m = $n ?? $o;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$a = $b && {o5}$c{c};
    {p1}$d = $e || {o6}$f{c};
    {p2}$g = $h and {o7}$i{c};
    {p3}$j = $k or {o8}$l{c};
    {p4}$m = $n ?? {o9}$o{c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
            2 => new Probe(4, false, '{main}', 0, 0),
            3 => new Probe(5, false, '{main}', 0, 0),
            4 => new Probe(6, false, '{main}', 0, 0),
            5 => new Probe(2, true, '{main}', 2, 2),
            6 => new Probe(3, true, '{main}', 3, 3),
            7 => new Probe(4, true, '{main}', 4, 4),
            8 => new Probe(5, true, '{main}', 5, 5),
            9 => new Probe(6, true, '{main}', 6, 6),
        ]);
});

it('probes the left operand deciding alone in strict mode, except for ??', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $a = $b && $c;
    $d = $e || $f;
    $g = $h and $i;
    $j = $k or $l;
    $m = $n ?? $o;
    PHP . "\n", strict: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$a = {s6:false}$b) && {o5}$c{c};
    {p1}$d = {s8:true}$e) || {o7}$f{c};
    {p2}{s10:false}$g = $h) and {o9}$i{c};
    {p3}{s12:true}$j = $k) or {o11}$l{c};
    {p4}$m = $n ?? {o13}$o{c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
            2 => new Probe(4, false, '{main}', 0, 0),
            3 => new Probe(5, false, '{main}', 0, 0),
            4 => new Probe(6, false, '{main}', 0, 0),
            5 => new Probe(2, true, '{main}', 2, 2),
            6 => new Probe(null, true, '{main}', 2, 2),
            7 => new Probe(3, true, '{main}', 3, 3),
            8 => new Probe(null, true, '{main}', 3, 3),
            9 => new Probe(4, true, '{main}', 4, 4),
            10 => new Probe(null, true, '{main}', 4, 4),
            11 => new Probe(5, true, '{main}', 5, 5),
            12 => new Probe(null, true, '{main}', 5, 5),
            13 => new Probe(6, true, '{main}', 6, 6),
        ]);
});

it('nests the short-circuit pass-through around a chained left operand and its line probe', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if (
        $a !== '' &&
        $b &&
        $c
    ) {
        echo 1;
    }
    PHP . "\n", strict: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if (
        {s4:false}{s6:false}{o7}$a !== ''{c}) &&
        {o5}$b{c}) &&
        {o3}$c{c}
    ) {
        {p1}echo 1;
    } else { {p2} }
    PHP . "\n"))
        ->and($probes[3])->toEqual(new Probe(5, true, '{main}', 5, 5))
        ->and($probes[4])->toEqual(new Probe(null, true, '{main}', 3, 4))
        ->and($probes[5])->toEqual(new Probe(4, true, '{main}', 4, 4))
        ->and($probes[6])->toEqual(new Probe(null, true, '{main}', 3, 3))
        ->and($probes[7])->toEqual(new Probe(3, false, '{main}', 3, 3));
});

it('probes the leftmost operand when it has a line of its own', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if (
        $a !== '' &&
        $b &&
        $c
    ) {
        echo 1;
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if (
        {o4}$a !== ''{c} &&
        {o3}$b{c} &&
        {o2}$c{c}
    ) {
        {p1}echo 1;
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(7, true, '{main}', 7, 7),
            2 => new Probe(5, true, '{main}', 5, 5),
            3 => new Probe(4, true, '{main}', 4, 4),
            4 => new Probe(3, false, '{main}', 3, 3),
        ]);
});

it('wraps the whole ?? when its left side has a line of its own, keeping that side silent', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $tone = $overrides['tone']
        ?? $defaults['tone']
        ?? 'neutral';
    $label = (
        $names[$id]
        ?? 'none'
    );
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$tone = $overrides['tone']
        ?? {o2}$defaults['tone']
        ?? {o3}'neutral'{c}{c};
    {p1}$label = (
        {o5}$names[$id]
        ?? {o4}'none'{c}{c}
    );
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(5, false, '{main}', 0, 0),
            2 => new Probe(3, true, '{main}', 3, 4),
            3 => new Probe(4, true, '{main}', 4, 4),
            4 => new Probe(7, true, '{main}', 7, 7),
            5 => new Probe(6, false, '{main}', 6, 7),
        ]);
});
it('wraps the right side of ??=', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $cache ??= load();
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$cache ??= {o1}load(){c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(2, true, '{main}', 2, 2),
        ]);
});

it('wraps both arms of ternaries', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $a = $b ? $c : $d;
    $e = $f ?: $g;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$a = $b ? {o2}$c{c} : {o3}$d{c};
    {p1}$e = $f ?: {o4}$g{c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
            2 => new Probe(2, true, '{main}', 2, 2),
            3 => new Probe(2, true, '{main}', 2, 2),
            4 => new Probe(3, true, '{main}', 3, 3),
        ]);
});

it('wraps match arms and their non-literal conditions', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $label = match ($status) {
        1, 2 => 'low',
        $limit => 'limit',
        Status::Active => 'active',
        default => 'other',
    };
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$label = match ({o1}$status{c}) {
        1, 2 => {o2}'low'{c},
        {o3}$limit{c} => {o4}'limit'{c},
        {o5}Status::Active{c} => {o6}'active'{c},
        default => {o7}'other'{c},
    };
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(6, false, '{main}', 6, 6),
            2 => new Probe(3, true, '{main}', 3, 3),
            3 => new Probe(4, false, '{main}', 4, 4),
            4 => new Probe(4, true, '{main}', 4, 4),
            5 => new Probe(5, false, '{main}', 5, 5),
            6 => new Probe(5, true, '{main}', 5, 5),
            7 => new Probe(6, true, '{main}', 6, 6),
        ]);
});

it('marks the line of the last arm whenever the match runs', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $color = match ($status) {
        Status::Active => 'success',
        Status::Suspended => 'danger',
    };
    $none = match ($value) {};
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$color = match ({o2}$status{c}) {
        {o3}Status::Active{c} => {o4}'success'{c},
        {o5}Status::Suspended{c} => {o6}'danger'{c},
    };
    {p1}$none = match ($value) {};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(6, false, '{main}', 0, 0),
            2 => new Probe(4, false, '{main}', 4, 4),
            3 => new Probe(3, false, '{main}', 3, 3),
            4 => new Probe(3, true, '{main}', 3, 3),
            5 => new Probe(4, false, '{main}', 4, 4),
            6 => new Probe(4, true, '{main}', 4, 4),
        ]);
});
it('replaces __FILE__ and __DIR__ with the original paths', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $file = __FILE__;
    $dir = __DIR__;
    $path = $base ?? __DIR__;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$file = '/project/src/File.php';
    {p1}$dir = '/project/src';
    {p2}$path = $base ?? {o3}'/project/src'{c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
            2 => new Probe(4, false, '{main}', 0, 0),
            3 => new Probe(4, true, '{main}', 4, 4),
        ]);
});

it('binds a local reference in fast mode, except in arrow functions and declaration-only bodies', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    function total(array $items): int
    {
        $sum = 0;
        foreach ($items as $item) {
            $sum += $item ?: 0;
        }
        $double = fn (int $value): int => $value * 2;

        return $double($sum);
    }
    function onlyDeclarations(): void
    {
        function inner(): void
        {
            echo 1;
        }
    }
    $top = 1;
    PHP . "\n", fast: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    function total(array $items): int
    {
        {ref}{l1}$sum = 0;
        {l2}foreach ($items as $item) {
            {l5}$sum += $item ?: {L6}0{c};
        }
        {l3}$double = fn (int $value): int => {o7}$value * 2{c};

        {l4}return $double($sum);
    }
    function onlyDeclarations(): void
    {
        function inner(): void
        {
            {ref}{l9}echo 1;
        }
    {p8}}
    {p0}$top = 1;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(19, false, '{main}', 0, 0),
            1 => new Probe(4, true, 'total', 4, 10),
            2 => new Probe(5, false, 'total', 0, 0),
            3 => new Probe(8, false, 'total', 0, 0),
            4 => new Probe(10, false, 'total', 0, 0),
            5 => new Probe(6, true, 'total', 6, 6),
            6 => new Probe(6, true, 'total', 6, 6),
            7 => new Probe(8, true, '{closure}', 8, 8, [8]),
            8 => new Probe(18, true, 'onlyDeclarations', 18, 18),
            9 => new Probe(16, true, 'inner', 16, 16),
        ]);
});
it('probes executable statements of a namespace', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    namespace App;

    $ready = true;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    namespace App;

    {p0}$ready = true;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(4, false, '{main}', 0, 0),
        ]);
});

it('returns to the enclosing class and function after nested ones', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    class Report
    {
        public function build(): int
        {
            $helper = new class {
                public function run(): int
                {
                    return 1;
                }
            };
            $format = function (): string {
                return 'x';
            };

            return $helper->run() ?: 0;
        }

        public function name(): string
        {
            return 'report';
        }
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    class Report
    {
        public function build(): int
        {
            {p0}$helper = new class {
                public function run(): int
                {
                    {p3}return 1;
                }
            };
            {p1}$format = function (): string {
                {p4}return 'x';
            };

            {p2}return $helper->run() ?: {o5}0{c};
        }

        public function name(): string
        {
            {p6}return 'report';
        }
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(6, true, 'Report->build', 6, 16),
            1 => new Probe(12, false, 'Report->build', 0, 0),
            2 => new Probe(16, false, 'Report->build', 0, 0),
            3 => new Probe(9, true, 'class@anonymous->run', 9, 9),
            4 => new Probe(13, true, '{closure}', 13, 13),
            5 => new Probe(16, true, 'Report->build', 16, 16),
            6 => new Probe(21, true, 'Report->name', 21, 21),
        ]);
});

it('keeps using the local reference after a nested arrow function', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    function pick(array $items): int
    {
        $first = fn (array $list): int => $list[0];

        return $first($items) ?: 0;
    }
    PHP . "\n", fast: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    function pick(array $items): int
    {
        {ref}{l0}$first = fn (array $list): int => {o2}$list[0]{c};

        {l1}return $first($items) ?: {L3}0{c};
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(4, true, 'pick', 4, 6),
            1 => new Probe(6, false, 'pick', 0, 0),
            2 => new Probe(4, true, '{closure}', 4, 4, [4]),
            3 => new Probe(6, true, 'pick', 6, 6),
        ]);
});

it('leaves enum case values untouched', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    enum Size: int
    {
        case Large = 1 ?: 2;
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    enum Size: int
    {
        case Large = 1 ?: 2;
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
        ]);
});

it('leaves the lines of a nested closure out of an arrow function', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $build = fn (array $row): array => [
        'format' => function ($value) {
            return $value;
        },
        'total' => 1,
    ];
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$build = fn (array $row): array => {o1}[
        'format' => function ($value) {
            {p2}return $value;
        },
        'total' => 1,
    ]{c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(2, true, '{closure}', 2, 7, [2, 3, 6, 7]),
            2 => new Probe(4, true, '{closure}', 4, 4),
        ]);
});

it('closes inner wrappers before outer ones at the same offset', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $value = $a ?? $b ?? $c;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$value = $a ?? {o1}$b ?? {o2}$c{c}{c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(2, true, '{main}', 2, 2),
            2 => new Probe(2, true, '{main}', 2, 2),
        ]);
});

it('closes an unbraced body before the probe of the next statement', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if($a)echo 1;echo 2;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if($a){ {p2}echo 1; }{p1}echo 2;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(2, false, '{main}', 0, 0),
            2 => new Probe(2, true, '{main}', 2, 2),
        ]);
});

it('adds an else before the endif of alternative-syntax ifs, with or without statements', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if ($a):
        echo 1;
    elseif ($b):
        echo 2;
    endif;
    if ($c): endif;
    PHP . "\n", strict: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if ($a):
        {p2}echo 1;
    elseif ({o4}$b{c}):
        {p5}echo 2;
    else: {p3} endif;
    {p1}if ($c): {p6}else: {p7} endif;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(7, false, '{main}', 0, 0),
            2 => new Probe(3, true, '{main}', 3, 3),
            3 => new Probe(null, true, '{main}', 6, 6),
            4 => new Probe(4, false, '{main}', 4, 4),
            5 => new Probe(5, true, '{main}', 5, 5),
            6 => new Probe(null, true, '{main}', 7, 7),
            7 => new Probe(null, true, '{main}', 7, 7),
        ]);
});

it('adds an else to an unbraced if around an alternative-syntax if', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if ($a) if ($b): echo 1; endif;
    PHP . "\n", strict: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if ($a) { {p1}if ($b): {p3}echo 1; else: {p4} endif; } else { {p2} }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(2, true, '{main}', 2, 2),
            2 => new Probe(null, true, '{main}', 2, 2),
            3 => new Probe(2, true, '{main}', 2, 2),
            4 => new Probe(null, true, '{main}', 2, 2),
        ]);
});
it('adds an else to an unbraced if whose last elseif holds an alternative-syntax if', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if ($a) echo 1;
    elseif ($b) if ($c): echo 2; endif;
    PHP . "\n", strict: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if ($a) { {p1}echo 1; }
    elseif ({o3}$b{c}) { {p4}if ($c): {p5}echo 2; else: {p6} endif; } else { {p2} }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(2, true, '{main}', 2, 2),
            2 => new Probe(null, true, '{main}', 3, 3),
            3 => new Probe(3, false, '{main}', 3, 3),
            4 => new Probe(3, true, '{main}', 3, 3),
            5 => new Probe(3, true, '{main}', 3, 3),
            6 => new Probe(null, true, '{main}', 3, 3),
        ]);
});
it('leaves an unbraced body that cannot be probed unbraced', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if ($a) declare(ticks=1);
    echo 2;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if ($a) declare(ticks=1);
    {p1}echo 2;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
        ]);
});
it('keeps probes inside PHP mode in templates', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <ul>
    <?php if ($items !== []) { ?>
        <?php foreach ($items as $item): ?>
            <li><?= $item, ' ', $item ?></li>
        <?php endforeach ?>
    <?php } ?>
    <?php if ($footer): ?>
        <p>end</p>
    <?php endif ?>
    </ul>
    PHP . "\n", strict: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php {p0} ?><ul>
    <?php {p1}if ($items !== []) { ?>
    <?php {p4} ?>    <?php {p5}foreach ($items as $item): ?>
    <?php {p7} ?>        <li><?= {o8}$item{c}, ' ', $item ?><?php {p9} ?></li>
        <?php endforeach ?>
    <?php } else { {p6} } ?>
    <?php {p2}if ($footer): ?>
    <?php {p10} ?>    <p>end</p>
    <?php else: {p11} endif ?>
    <?php {p3} ?></ul>
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(1, false, '{main}', 0, 0),
            1 => new Probe(2, false, '{main}', 0, 0),
            2 => new Probe(7, false, '{main}', 0, 0),
            3 => new Probe(10, false, '{main}', 0, 0),
            4 => new Probe(3, true, '{main}', 3, 6),
            5 => new Probe(3, false, '{main}', 0, 0),
            6 => new Probe(null, true, '{main}', 6, 6),
            7 => new Probe(4, true, '{main}', 4, 5),
            8 => new Probe(4, false, '{main}', 0, 0),
            9 => new Probe(4, false, '{main}', 0, 0),
            10 => new Probe(8, true, '{main}', 8, 8),
            11 => new Probe(null, true, '{main}', 9, 9),
        ]);
});

it('binds the local reference inside the tag of inline HTML that opens a function', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php function row(string $name): void { ?>
        <td><?= $name ?></td>
        <?php echo 1; ?>
    <?php }
    PHP . "\n", fast: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php function row(string $name): void { ?>
    <?php {ref}{l0} ?>    <td><?= {L1}$name{c} ?><?php {l2} ?></td>
        <?php {l3}echo 1; ?>
    <?php }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, true, 'row', 2, 3),
            1 => new Probe(2, false, 'row', 0, 0),
            2 => new Probe(2, false, 'row', 0, 0),
            3 => new Probe(3, false, 'row', 0, 0),
        ]);
});
it('probes the value before each ?->, keeping the chain', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $name = $order?->customer()?->name;
    $total = $order?->items->count();
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$name = \Sonda\Hits::nullsafe('f', 2, null, \Sonda\Hits::nullsafe('f', 3, null, $order)?->customer())?->name;
    {p1}$total = \Sonda\Hits::nullsafe('f', 4, null, $order)?->items->count();
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
            2 => new Probe(null, true, '{main}', 2, 2),
            3 => new Probe(null, true, '{main}', 2, 2),
            4 => new Probe(null, true, '{main}', 3, 3),
        ]);
});

it('probes both outcomes of ?-> in strict mode', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $name = $order?->customer;
    PHP . "\n", strict: true);

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$name = \Sonda\Hits::nullsafe('f', 1, 2, $order)?->customer;
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(null, true, '{main}', 2, 2),
            2 => new Probe(null, true, '{main}', 2, 2),
        ]);
});

it('keeps an undefined left side of ?-> silent inside isset, empty and ??', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $a = isset($data['user']?->name);
    $b = empty($user?->email);
    $c = $payload['customer']?->name ?? 'none';
    $d = $fallback ?? $user?->name;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$a = isset(\Sonda\Hits::nullsafe('f', 4, null, $data['user'] ?? null)?->name);
    {p1}$b = empty(\Sonda\Hits::nullsafe('f', 5, null, $user ?? null)?->email);
    {p2}$c = \Sonda\Hits::nullsafe('f', 7, null, $payload['customer'] ?? null)?->name ?? {o6}'none'{c};
    {p3}$d = $fallback ?? {o8}\Sonda\Hits::nullsafe('f', 9, null, $user)?->name{c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
            2 => new Probe(4, false, '{main}', 0, 0),
            3 => new Probe(5, false, '{main}', 0, 0),
            4 => new Probe(null, true, '{main}', 2, 2),
            5 => new Probe(null, true, '{main}', 3, 3),
            6 => new Probe(4, true, '{main}', 4, 4),
            7 => new Probe(null, true, '{main}', 4, 4),
            8 => new Probe(5, true, '{main}', 5, 5),
            9 => new Probe(null, true, '{main}', 5, 5),
        ]);
});
it('silences ?-> method calls in isset, but not the operands of &&', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    $a = isset($data['user']?->profile()->name);
    $b = $user?->name && $active;
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}$a = isset(\Sonda\Hits::nullsafe('f', 2, null, $data['user'] ?? null)?->profile()->name);
    {p1}$b = \Sonda\Hits::nullsafe('f', 4, null, $user)?->name && {o3}$active{c};
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(3, false, '{main}', 0, 0),
            2 => new Probe(null, true, '{main}', 2, 2),
            3 => new Probe(3, true, '{main}', 3, 3),
            4 => new Probe(null, true, '{main}', 3, 3),
        ]);
});
it('leaves a ?? on the left of && to probe its own line', function (): void {
    [$code, $probes] = instrumentCode(<<<'PHP'
    <?php
    if (
        ($settings['enabled'] ?? false)
        && $ready
    ) {
        echo 1;
    }
    PHP . "\n");

    expect($code)->toBe(expected(<<<'PHP'
    <?php
    {p0}if (
        ({o4}$settings['enabled'] ?? {o3}false{c}{c})
        && {o2}$ready{c}
    ) {
        {p1}echo 1;
    }
    PHP . "\n"))
        ->and($probes)->toEqual([
            0 => new Probe(2, false, '{main}', 0, 0),
            1 => new Probe(6, true, '{main}', 6, 6),
            2 => new Probe(4, true, '{main}', 4, 4),
            3 => new Probe(3, true, '{main}', 3, 3),
            4 => new Probe(3, false, '{main}', 3, 3),
        ]);
});

it('gives back the CRLF that the probe tag eats before inline HTML that starts with one and ends without', function (): void {
    [$code] = instrumentCode("\r\n<p>x</p><?php echo 1;\r\n");

    expect($code)->toBe(expected("<?php {p0} ?>\r\n\r\n<p>x</p><?php {p1}echo 1;\r\n"));
});

it('leaves a hook declared without a body alone', function (): void {
    [$code, $probes] = instrumentCode("<?php\nabstract class Shape\n{\n    abstract public string \$name { get; }\n}\n");

    expect($code)->toBe("<?php\nabstract class Shape\n{\n    abstract public string \$name { get; }\n}\n")
        ->and($probes)->toBe([]);
});
