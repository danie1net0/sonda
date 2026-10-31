---
title: Introduction
slug: /
sidebar_position: 1
description: What Sonda is, why it exists and what it gives you.
---

Line and branch coverage for PHP by source instrumentation: no Xdebug, no PCOV, the suite runs at
its normal speed, and branches are counted with a strict semantics that asks for both outcomes of
every decision.

## Why

Branch coverage in PHP has one source today, Xdebug with `--path-coverage`, and it is slow and
heavy: it costs about 30 times the time of the suite, and php-code-coverage accumulates, on every
test, the test ids on every branch and path of the loaded files, so the cost per test grows along
the run. On a real Laravel suite it did not finish in an hour. PCOV is fast, but it only counts
lines, and both need an extension installed on every machine that measures.

Sonda inserts probes into the code before loading it, as Istanbul does for JavaScript. Each probe
is one assignment into an array; there is no opcode interception and no path enumeration, which is
what makes Xdebug slow and heavy. Its numbers were validated against Xdebug path coverage on a
whole real-world suite (see [Comparison with Xdebug](comparison-with-xdebug.md)).

## What you get

- **Lines and branches**, from the same run, with reports in HTML, Clover, Cobertura, `.cov` and
  GitLab Code Quality, built by php-code-coverage.
- **No extension**: Sonda is a Composer dependency, or a clone on the PATH, and uses the
  PHPUnit, php-code-coverage and php-parser of the measured project.
- **The suite at its normal speed**: measuring costs seconds, not multiples of the suite.
- **Strict branch semantics**: every `if` without `else` needs both outcomes tested, `?->` counts
  both sides, `&&` and `||` need the short circuit. See [Branch semantics](branch-semantics.md).
- **Blade views** measured line by line and branch by branch, in Laravel projects. See
  [Blade views](blade-views.md).
- **Built for CI**: `--changed` measures only what the merge request touched and demands 100% of
  it; `--affected` runs only the tests that execute what changed. See
  [Only what changed](usage/only-what-changed.md) and [Only the affected tests](usage/affected-tests.md).

## Headline numbers

The suite of a Laravel 13 project with Filament, Pest 4 and Postgres, 4,514 tests:

| Run | Time | Memory |
|---|---|---|
| No coverage | 178s | 308 MB |
| Sonda | 174s | 317 MB |
| Xdebug, lines only | 671s | 640 MB |
| Xdebug with `--path-coverage` | did not finish in 63 min | more than 760 MB |

With `--parallel` (10 workers): 42s without coverage, 40s with Sonda. The whole table, the
fidelity results and how to reproduce the comparison on your own project are in
[Comparison with Xdebug](comparison-with-xdebug.md).

## Where to go next

- [Getting started](getting-started.md): requirements, installation, first run.
- [CLI reference](usage/cli-reference.md): every option.
- [In CI](ci/gitlab.md): GitLab and GitHub Actions recipes.
- [How it works](how-it-works.md): probes, cache, autoloader, workers.
