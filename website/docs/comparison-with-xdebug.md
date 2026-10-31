---
title: Comparison with Xdebug
sidebar_position: 10
description: Time and memory against Xdebug and PCOV, fidelity on a whole suite, and how to reproduce the comparison.
---

Sonda's numbers were validated against Xdebug path coverage on a whole real-world suite. This page
has the measurements and the command that reproduces the comparison on your own project.

## Time and memory

The suite of a Laravel 13 project with Filament, Pest 4 and Postgres, 4,514 tests:

| Run | Time | Memory |
|---|---|---|
| No coverage | 178s | 308 MB |
| Sonda | 174s | 317 MB |
| Xdebug, lines only | 671s | 640 MB |
| Xdebug with `--path-coverage` | did not finish in 63 min | more than 760 MB |

Xdebug with paths only finished the whole suite when split into batches of ~80 tests, each in a
new process: php-code-coverage accumulates, on every test, the test ids on every branch and path of
the loaded files, and the cost per test grows along the run (from 56 to 3 tests per minute with 6
workers). In batches, 48 processes in 6 queues, 2h24; straight through, it did not fit in a night.

With `--parallel` (10 workers): 42s without coverage, 40s with Sonda.
Peak memory per worker, across the 10 workers of `tests/Feature/Filament` in that suite, with the
include wrapper on: 52 MB. Up to 1.14.2, that wrapper held memory on every file operation, and the
peak reached 107 MB per worker.

The cost of Xdebug with paths is Xdebug's own, in C, and it is per test: in a file of 71 tests of
that suite, 8s with Xdebug loaded and coverage off, 257s with `XDEBUG_CC_BRANCH_CHECK` enabled once
in the bootstrap, without php-code-coverage processing anything. Restricting the measured files does
not change that number.

## Fidelity

Measured on the whole suite of that application (4,923 tests, 697 files in `app/`, Xdebug with
paths run in batches of tests, 2h24 on a Mac mini M1):

- 12.1 thousand executable lines, no divergence on which lines are covered or not; 5 lines where
  only one of the tools sees an executable point, all in multi-line statements (heredoc, returned
  `match`, closing of a closure).
- Branch verdicts per method: 2,333 equal; 21 where only Xdebug gives 100%, all because of Sonda's
  strict mode; 42 where only Sonda gives 100%, with 108 blocks that Xdebug reports as missed while
  every line in them is executed by its own count: the fallback jump of a frameless function call,
  the body of an `if` ending in `return`/`throw`, the implicit arm of `match` and duplicated blocks
  of `&&`/`||`.
- No unexecuted code was hidden behind a 100% from Sonda.

The rules behind the differences are in [Branch semantics](branch-semantics.md).

## Reproducing it: `sonda compare`

```bash
sonda compare <output directory>
```

At the root of a project, `sonda compare` runs the suite twice, with Sonda and with Xdebug in
`--parallel`, and leaves `sonda.cov`, `xdebug.cov`, `compare.txt` and `summary.txt` in the given
directory. It needs a private database in `DB_DATABASE` in the environment and an idle machine.
Extra arguments go to the runner: `sonda compare output tests/Feature/Orders` measures only that
directory.

| Option | Meaning |
|---|---|
| `--processes=<n>` | ParaTest workers, or batch queues (default 3) |
| `--batch=<n>` | tests per batch on the Xdebug side, which in a large suite does not finish in one go |
| `--xdebug=<so>` | the `xdebug.so` to load |

It generates its own `phpunit.xml` with `pathCoverage="true"`, because ParaTest does not accept
`--path-coverage`, and with absolute paths. PHPUnit's `--coverage-filter` does not restrict
anything: it adds to the `<source>` of `phpunit.xml`. On a big suite, `--batch=80` runs the Xdebug
side in batches of up to 80 tests, one process per batch and `--processes` queues with their own
database (`${DB_DATABASE}_q<N>`), and merges the `.cov` files at the end: that is how the Laravel
suite above finished.

The command wraps `tools/compare-with-xdebug.sh` (`--processes`, `--batch` and `--xdebug` become
the variables `PROCESSES`, `BATCH` and `XDEBUG_SO`). `php tools/compare.php xdebug.cov sonda.cov -v`
compares two `.cov` files line by line and method by method, merging the first-class-callable
entries of Xdebug into their methods first.
