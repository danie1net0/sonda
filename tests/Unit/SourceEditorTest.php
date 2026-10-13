<?php

declare(strict_types=1);

use CovProto\Edit;
use CovProto\SourceEditor;

it('returns the code untouched without edits', function (): void {
    expect(SourceEditor::apply('abc', []))->toBe('abc');
});

it('inserts text at byte offsets in sorted order', function (): void {
    $edits = [new Edit(3, true, 0, '<3>'), new Edit(0, true, 0, '<0>'), new Edit(1, true, 0, '<1>')];

    expect(SourceEditor::apply('abc', $edits))->toBe('<0>a<1>bc<3>');
});

it('nests wrappers that start or end at the same offset', function (): void {
    $edits = [
        new Edit(0, true, 2, '[inner '),
        new Edit(0, true, 1, '[outer '),
        new Edit(1, false, -1, ' outer]'),
        new Edit(1, false, -2, ' inner]'),
    ];

    expect(SourceEditor::apply('x', $edits))->toBe('[outer [inner x inner] outer]');
});

it('replaces a range of the original code', function (): void {
    expect(SourceEditor::apply('a__FILE__b', [new Edit(1, true, 0, "'f.php'", 8)]))->toBe("a'f.php'b");
});
