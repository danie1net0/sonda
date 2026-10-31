---
title: CLI reference
sidebar_position: 5
description: Every option of sonda test and sonda compare.
---

`sonda test` measures the project in the current directory; `sonda compare` measures it with Sonda
and with Xdebug and compares the two. `sonda --version` (or `-V`) prints the version of the sonda
that is running; `sonda help` prints this reference.

## `sonda test [options] [runner arguments]`

Runs the tests of the project in the current directory with Sonda's instrumentation and prints the
line and branch coverage of the `<source>` of `phpunit.xml`. Nothing is installed in the project.

### Scope and verdict

| Option | Meaning |
|---|---|
| `--changed=<ref>` | measures only the `<source>` files changed since the merge-base with the reference (committed, modified and new) and fails if the changed lines or the touched methods have anything uncovered. See [Only what changed](only-what-changed.md) |
| `--files=a,b` | measures only those files and fails if anything is left uncovered in them, whole |
| `--affected` | with `--changed` or `--files`, runs only the tests that execute what changed, by the map each run writes; without a map, or with a change it does not explain (config, routes, a new file), runs the whole suite. See [Only the affected tests](affected-tests.md) |
| `--map=<file>` | where to keep the test map of `--affected`, for the CI cache |
| `--min-lines=<n>` | fails if the line coverage of the whole project is below n% (global floor, on top of the 100% of what changed). With a floor, Sonda instruments and measures the whole `<source>` even with `--changed` or `--files`, whose verdict keeps looking only at their files; the printed total is the project's |
| `--min-branches=<n>` | the same for branches |

### Reports

| Option | Meaning |
|---|---|
| `--html=<dir>` | writes the HTML report |
| `--clover=<file>` | writes the Clover report |
| `--cov=<file>` | writes php-code-coverage's `.cov`, which serves any other format |
| `--cobertura=<file>` | writes the Cobertura report, the format GitLab reads to mark coverage in the MR diff |
| `--code-quality=<file>` | writes what is left uncovered as a GitLab Code Quality report: every stretch of lines and every branch without a test becomes a finding in the MR diff. With `--changed` or `--files`, only what the verdict demands; without them, the whole project. Paths start at the repository root |
| `--text` | lists the coverage of each file after the total |
| `--covered-by` | every covered line and branch carries the names of the tests that executed it, as with Xdebug; in the HTML, they show on hovering the line. It weighs on a big suite: with 5,615 tests, the HTML went from 123 MB to 1.3 GB and the report's memory from 300 MB to 870 MB |

### What is measured and how

| Option | Meaning |
|---|---|
| `--no-strict` | does not require the `else` of every `if` nor the null side of `?->`. See [Branch semantics](../branch-semantics.md) |
| `--no-fast` | probes without the local reference per function (one assignment per probe is the default) |
| `--no-parallel` | does not use ParaTest even when installed |
| `--no-blade` | does not measure the Blade views (`resources/views`) of a Laravel project |
| `--views=a,b` | also measures the Blade views of those directories. See [Blade views](../blade-views.md) |
| `--includes` | also measures the files of the target directory loaded by a direct `include` or `require` (default: only when the source has files without a class, such as migrations) |
| `--no-includes` | does not measure those files |
| `-c <file>` | the `phpunit.xml` to use, also passed on to the runner |

### Runner arguments

Any other argument goes to the runner (`php artisan test`, `pest`, `paratest` or `phpunit`,
whichever the project has): `sonda test --filter=Order`, `sonda test tests/Feature/Actions`,
`sonda test --processes=3`. Several paths (`sonda test tests/Unit tests/Feature/Actions`) run in
parallel just the same: ParaTest only takes one on the command line, so Sonda puts them in a
`phpunit.xml` generated next to the project's, deleted at the end.

## `sonda compare <output> [options] [runner arguments]`

Measures the project with Sonda and with Xdebug (path coverage) and compares the two, writing
`sonda.cov`, `xdebug.cov`, `compare.txt` and `summary.txt` in `<output>`. Needs a private database
in `DB_DATABASE` in the environment and an idle machine. See
[Comparison with Xdebug](../comparison-with-xdebug.md) for what it does and what the results look like.

| Option | Meaning |
|---|---|
| `--processes=<n>` | ParaTest workers, or batch queues (default 3) |
| `--batch=<n>` | tests per batch on the Xdebug side, which in a large suite does not finish in one go |
| `--xdebug=<so>` | the `xdebug.so` to load |

Extra arguments go to the runner: `sonda compare output tests/Feature/Orders` measures only that
directory.

## `sonda --version`

Prints `sonda <version>`: the version Composer installed (`sonda 1.16.0`), or, in a clone, the
nearest tag and the commits past it (`sonda 1.16.0-2-g8e8a744`).
