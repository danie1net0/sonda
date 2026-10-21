<?php

declare(strict_types=1);

use Sonda\Report\Compat\V11ViewFileAnalyser;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser;

beforeEach(function (): void {
    if (onCoverage12()) {
        $this->markTestSkipped('php-code-coverage 11 only');
    }
});

it('reads a view through its analysis copy and any other file as it is, for every question', function (): void {
    $root = TemporaryDirectory::create();
    $code = "<?php\n// @codeCoverageIgnoreStart\n\$ignored = 1;\n// @codeCoverageIgnoreEnd\n\nfunction view(): int\n{\n    return 1;\n}\n\nfinal class Order\n{\n    public function total(): int\n    {\n        return 2;\n    }\n}\n\ntrait Named\n{\n}\n";
    file_put_contents($root . '/home.blade.php', "<h1>{{ \$title }}</h1>\n");
    file_put_contents($root . '/copy.php', $code);
    file_put_contents($root . '/Same.php', $code);
    $analyser = new V11ViewFileAnalyser([$root . '/home.blade.php' => $root . '/copy.php']);
    $plain = new ParsingFileAnalyser(true, false);

    foreach (['classesIn', 'traitsIn', 'functionsIn', 'linesOfCodeFor', 'executableLinesIn', 'ignoredLinesFor'] as $question) {
        expect($analyser->{$question}($root . '/home.blade.php'))->toEqual($plain->{$question}($root . '/copy.php'), $question)
            ->and($analyser->{$question}($root . '/Same.php'))->toEqual($plain->{$question}($root . '/Same.php'), $question);
    }

    expect(array_keys($analyser->functionsIn($root . '/home.blade.php')))->toBe(['view'])
        ->and(array_keys($analyser->classesIn($root . '/home.blade.php')))->toBe(['Order'])
        ->and(array_keys($analyser->traitsIn($root . '/home.blade.php')))->toBe(['Named'])
        ->and($analyser->ignoredLinesFor($root . '/home.blade.php'))->toContain(3);
});
