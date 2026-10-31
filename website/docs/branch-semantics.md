---
title: Branch semantics
sidebar_position: 9
description: What Sonda counts as a branch, what the strict mode demands, and what it leaves out on purpose.
---

A branch is one outcome of a decision: the entry of a function, the body of `if`, `elseif`,
`else`, a loop, `case`, `catch` and `finally`, the right operand of `&&`, `||`, `and`, `or` and
`??`, the arms of `?:` and `match`, and the non-null value of `?->`.

## Strict mode

Strict mode is the default; `--no-strict` turns off the first and the fourth rules below (the
synthetic `else` and the null side of `?->`).

- **An empty body is an outcome.** An empty body of `if`, `elseif`, `else` and loops is an outcome
  like any other: it gets a probe without a line, and counts as a branch.

  ```php
  if ($order->isPaid()) {
      // nothing to do: this outcome still has to be reached by a test
  }
  ```

- **Arrow functions become closures.** `fn&(...) => expr` becomes the closure it abbreviates,
  `function &(...) use (...) { probe; return expr; }`, with the variables of the expression captured
  by value as the arrow function does; `&get => expr` becomes `&get { probe; return expr; }`. The
  returned reference stays intact.

- **Every `if` needs its `else`.** Every `if` without `else` gets a synthetic `else`: both outcomes
  need to be tested. Xdebug counts basic blocks, so an `if` without `else` whose condition is
  always true gives 100% there and not in Sonda.

  ```php
  public function total(): int
  {
      if ($this->discount !== null) {   // a test with a discount, and one without
          return $this->subtotal - $this->discount;
      }

      return $this->subtotal;
  }
  ```

- **`?->` counts both sides.** The null and the non-null outcome of every nullsafe call.

  ```php
  $name = $order->customer?->name();   // a test with a customer, and one with null
  ```

- **The short circuit of `&&` and `||`.** The left operand of `&&`, `||`, `and` and `or` has to
  decide alone at least once (the short circuit, when the right one does not even run), as Xdebug
  counts.

  ```php
  if ($user->isAdmin() || $user->owns($order)) {   // one test where isAdmin() is true, so owns() never runs
  ```

  Not in `??`: its left side may be an undefined key, which only that operator reads without a
  warning, so only the right operand of `??` is a branch.

  ```php
  $limit = $options['limit'] ?? 10;   // only the fallback (right side) needs a test
  ```

## What Sonda leaves out on purpose

- **The implicit arm of `match`.** Sonda does not count the implicit "no arm matched" branch of
  `match`, which Xdebug counts and no test reaches when the arms cover every case of an enum.

  ```php
  return match ($status) {
      Status::Open => 'open',
      Status::Closed => 'closed',
  };   // the arms are the branches; the UnhandledMatchError path is not one
  ```

- **The fallback jump of a frameless function call.** Sonda does not count the fallback jump
  Xdebug sees in a call to a native *frameless* function (`in_array`, `max`, `preg_replace`,
  `str_contains`...) made inside a namespace without `use function`: it is a detail of the opcode,
  not a decision of the code, and a `use function` only to silence the coverage stops being
  needed.

  ```php
  namespace App;

  $found = in_array($id, $ids, true);   // no "branch" here for Sonda; Xdebug sees the fallback to the global function
  ```

- **First-class callables credit the method.** A method called as a *first-class callable*
  (`$this->method(...)`) shows in Xdebug as a loose function named after the method, with the
  hits, while the class method stays at zero; Sonda credits the method. `tools/compare.php` merges
  the two entries before comparing.

The fidelity of these rules against Xdebug, measured on a whole suite, is in
[Comparison with Xdebug](comparison-with-xdebug.md).
