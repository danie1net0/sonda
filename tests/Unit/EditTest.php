<?php

declare(strict_types=1);

use CovProto\Edit;

it('orders edits by position first', function (): void {
    expect(Edit::compare(new Edit(1, true, 9, 'a'), new Edit(2, false, 0, 'b')))->toBe(-1)
        ->and(Edit::compare(new Edit(2, false, 0, 'b'), new Edit(1, true, 9, 'a')))->toBe(1);
});

it('applies closing edits before opening ones at the same position', function (): void {
    expect(Edit::compare(new Edit(5, false, 9, ')'), new Edit(5, true, 0, '(')))->toBe(-1);
});

it('applies edits of the same kind and position by order', function (): void {
    expect(Edit::compare(new Edit(5, true, 1, 'outer'), new Edit(5, true, 2, 'inner')))->toBe(-1)
        ->and(Edit::compare(new Edit(5, true, 2, 'a'), new Edit(5, true, 2, 'b')))->toBe(0);
});

it('replaces nothing by default', function (): void {
    expect(new Edit(0, true, 0, 'x'))->deleteLength->toBe(0);
});

it('creates opening edits that keep their creation order', function (): void {
    expect(Edit::opening(3, '(', 2))
        ->position->toBe(3)
        ->isOpening->toBeTrue()
        ->order->toBe(0)
        ->text->toBe('(')
        ->deleteLength->toBe(2);
});

it('creates closing edits with an order', function (): void {
    expect(Edit::closing(3, -7, ')'))
        ->position->toBe(3)
        ->isOpening->toBeFalse()
        ->order->toBe(-7)
        ->text->toBe(')')
        ->deleteLength->toBe(0);
});
