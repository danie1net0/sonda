# Sonda

[Português](README.pt-BR.md)

Line and branch coverage for PHP by source instrumentation: no Xdebug, no PCOV, the suite runs at
its normal speed, and branches are counted with a strict semantics that asks for both outcomes of
every decision.

Sonda inserts probes into the code before loading it, as Istanbul does for JavaScript. Each probe
is one assignment into an array; there is no opcode interception and no path enumeration, which is
what makes Xdebug slow and heavy. Its numbers were validated against Xdebug path coverage on a
whole real-world suite (see below).

## Measurements

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

Fidelity against Xdebug, measured on the whole suite of that application (4,923 tests, 697 files in
`app/`, Xdebug with paths run in batches of tests, 2h24 on a Mac mini M1): 12.1 thousand executable
lines, no divergence on which lines are covered or not; 5 lines where only one of the tools sees an
executable point, all in multi-line statements (heredoc, returned `match`, closing of a closure).
Branch verdicts per method: 2,333 equal; 21 where only Xdebug gives 100%, all because of Sonda's
strict mode; 42 where only Sonda gives 100%, with 108 blocks that Xdebug reports as missed while
every line in them is executed by its own count: the fallback jump of a frameless function call,
the body of an `if` ending in `return`/`throw`, the implicit arm of `match` and duplicated blocks
of `&&`/`||`. No unexecuted code was hidden behind a 100% from Sonda.

## Requirements

- PHP 8.4 or newer, with `git` on the PATH for `--changed`.
- In the measured project: PHPUnit 11.5 (Pest 3) or 12 (Pest 4), with the php-code-coverage of
  the same version, which bring the `nikic/php-parser` the instrumentation uses. Sonda uses
  PHPUnit's event facade, which is internal, and CI runs the suite on both versions, on the lowest
  and on the latest of each.

## Installation

As a development dependency of the project, which is the way to pin the version and let Renovate
or Dependabot update it:

```bash
composer require --dev ddr/sonda
vendor/bin/sonda test
```

Without installing anything in the measured project: one clone per machine, once, and the command
on the PATH. Sonda then uses the project's own dependencies (PHPUnit, php-code-coverage,
php-parser):

```bash
git clone https://github.com/danie1net0/sonda.git /opt/sonda
ln -s /opt/sonda/bin/sonda /usr/local/bin/sonda
```

Updating is `git -C /opt/sonda pull`. `sonda --version` prints the version of the sonda that is
running: the one Composer installed, or the tag of the checkout.

## Usage

At the root of the project:

```bash
sonda test
```

Sonda reads the `<source>` (`include` and `exclude`) and the `bootstrap` from `phpunit.xml`, runs
the tests with `php artisan test --parallel` (or `vendor/bin/pest`, `vendor/bin/paratest`,
`vendor/bin/phpunit`, whichever is there), and prints:

```
Coverage: lines 11140/11232 (99.18%), branches 4303/4371 (98.44%)
```

Any argument Sonda does not know goes to the runner: `sonda test --filter=Order`,
`sonda test tests/Feature/Actions`. Several paths (`sonda test tests/Unit tests/Feature/Actions`)
run in parallel just the same: ParaTest only takes one on the command line, so Sonda puts them in a
`phpunit.xml` generated next to the project's, deleted at the end.

### Only what changed

```bash
sonda test --changed=origin/master
```

Measures only the files of the `<source>` changed since the `merge-base` with the reference:
committed, modified and new. In a shallow clone, with no common history to find the `merge-base`,
the reference itself is the base, which is what CI hands over (`CI_MERGE_REQUEST_DIFF_BASE_SHA` on
GitLab). The verdict demands 100% **of what changed**: the lines the diff added or replaced and the
methods and functions they fall in, whole; a new file is demanded whole. An old gap in another
method of the same file does not fail. It lists what is left uncovered and exits with an error:

```
Coverage: lines 118/120 (98.33%), branches 61/63 (96.83%)
Of what changed: lines 40/42 (95.24%), branches 18/20 (90.00%)
Uncovered:
app/Actions/Scheduling/BookAppointmentAction.php: lines 57-58; branches 57-60
app/Models/ScheduleResource.php: branches 272
```

This is the intended use in CI: a job that measures only what the MR touched and demands 100%.
`--files=a.php,b.php` demands the listed files whole, even when they also changed.

On GitLab, the Cobertura and Code Quality reports together mark in the MR diff what is covered and
point, on the line itself, at what still needs a test:

```yaml
coverage:
  script:
    - php .sonda/bin/sonda test --changed="$CI_MERGE_REQUEST_DIFF_BASE_SHA" --cobertura=coverage/cobertura.xml --code-quality=coverage/code-quality.json
  artifacts:
    when: always
    reports:
      coverage_report:
        coverage_format: cobertura
        path: coverage/cobertura.xml
      codequality: coverage/code-quality.json
```

### Only the affected tests

```bash
sonda test --changed=origin/master --affected
```

Every run writes, outside the project, a map of which files of the `<source>` the tests of each
test file executed. With `--affected`, together with `--changed` or `--files`, Sonda runs only the
test files that execute what changed, plus the test files that changed. The tests covering the
changed code are all of those, so the verdict stays the same, in seconds instead of the whole
suite.

The map does not answer for everything, and then the whole suite runs, measuring the whole project
so the map learns: the first time, when a file of the `<source>` that no known test executes changes
(a new file, or one never executed), and when any file that is neither in the `<source>` nor a test
changes (configuration, routes, migrations, `composer.lock`, a test helper), because any test may
read it. The command states the reason before running. With a coverage floor, or with the tests to
run already given to the runner, `--affected` is set aside.

The map lives outside the project, in Sonda's work directory, and is refreshed on every run.
`--map=<file>` keeps it elsewhere, for example inside the project, so the CI cache carries it from
one job to the next. The file stores paths relative to the project, so it serves a checkout in
another directory, and it does not count as a change itself:

```yaml
coverage:
  cache:
    key: sonda-test-map
    paths: [.sonda/tests.map]
  script:
    - php .sonda/bin/sonda test --changed="$CI_MERGE_REQUEST_DIFF_BASE_SHA" --affected --map=.sonda/tests.map
```

A stale map never leaves an affected test out: every run rewrites what the test files that ran
execute, and a file of the `<source>` the map does not know makes the whole suite run. What it can
do is run extra tests. With a coverage floor (`--min-lines`, `--min-branches`), the whole suite runs
anyway.

### Options

| Option | Effect |
|---|---|
| `--changed=<ref>` | measures only the files changed since the merge-base with the reference and fails if the changed lines or the touched methods have anything uncovered |
| `--files=a,b` | measures only those files and fails if anything is left uncovered in them, whole |
| `--html=<dir>` | writes the HTML report |
| `--clover=<file>` | writes the Clover report |
| `--cov=<file>` | writes php-code-coverage's `.cov`, which serves any other format |
| `--cobertura=<file>` | writes the Cobertura report, the format GitLab reads to mark coverage in the MR diff |
| `--code-quality=<file>` | writes what is left uncovered as a GitLab Code Quality report: every stretch of lines and every branch without a test becomes a finding in the MR diff. With `--changed` or `--files`, only what the verdict demands; without them, the whole project. Paths start at the repository root |
| `--no-strict` | does not require the `else` of every `if` nor the null side of `?->` |
| `--no-fast` | probes without the local reference per function |
| `--no-parallel` | does not use ParaTest even when installed |
| `--no-blade` | does not measure the Blade views of a Laravel project |
| `--views=a,b` | also measures the Blade views of those directories |
| `--text` | lists the coverage of each file after the total |
| `--affected` | with `--changed` or `--files`, runs only the tests that execute what changed (see above) |
| `--includes`, `--no-includes` | instruments, or not, the files of the target directory loaded by a direct `include` or `require`; without them, Sonda decides on its own, turning it on when there are files the autoloader does not load |
| `--map=<file>` | where to keep the test map of `--affected`, for the CI cache (see above) |
| `--covered-by` | every covered line and branch carries the names of the tests that executed it, as with Xdebug; in the HTML, they show on hovering the line. It weighs on a big suite: with 5,615 tests, the HTML went from 123 MB to 1.3 GB and the report's memory from 300 MB to 870 MB |
| `--min-lines=<n>`, `--min-branches=<n>` | fails if the coverage of the whole project is below n% (global floor, on top of the 100% of what changed). With a floor, Sonda instruments and measures the whole `<source>` even with `--changed` or `--files`, whose verdict keeps looking only at their files; the printed total is the project's |
| `-c <file>` | the `phpunit.xml` to use, also passed on to the runner |
| `--version`, `-V` | prints the version of the sonda that is running |

Cache and runs stay outside the project, in `$XDG_CACHE_HOME/sonda` or `~/.cache/sonda`, in a
directory private to the user (mode 0700): the instrumented copies run as code, so nobody else may
write there. Sonda refuses a cache directory owned by another user or open to others.

### Without the command

`bootstrap.php` works directly in PHPUnit, Pest and ParaTest, configured by environment
variables; it is what the command does underneath:

```bash
SONDA_AUTOLOAD=vendor/autoload.php SONDA_TARGET=app SONDA_EXCLUDE=app/Providers \
SONDA_CACHE=/tmp/sonda/cache SONDA_OUT=/tmp/sonda/runs SONDA_STRICT=1 SONDA_FAST=1 \
php artisan test --parallel --bootstrap=/opt/sonda/bootstrap.php

php /opt/sonda/report.php /tmp/sonda/runs app coverage.cov app/Providers
```

| Variable | Required | Meaning |
|---|---|---|
| `SONDA_AUTOLOAD` | yes | `vendor/autoload.php` of the measured project |
| `SONDA_TARGET` | yes | instrumented directories, separated by `PATH_SEPARATOR` |
| `SONDA_CACHE` | yes | directory of the instrumented files |
| `SONDA_OUT` | yes | directory of the runs, one file per process; use a new one for each measurement |
| `SONDA_EXCLUDE` | no | directories left out |
| `SONDA_ONLY` | no | the only files measured |
| `SONDA_STRICT=1` | no | strict branch semantics |
| `SONDA_FAST=1` | no | local reference per function: cheaper probes |
| `SONDA_CHAIN` | no | the project's original bootstrap, when it is not just the autoload |
| `SONDA_VIEWS` | no | directories of Blade views to measure, separated by `PATH_SEPARATOR` |

## How it works

1. The bootstrap registers an autoloader ahead of Composer's. A class of the target directory is
   loaded from an instrumented copy, cached by path, modification time and instrumenter version.
   The files of Composer's `files` key that are inside the target (helpers) are claimed before
   Composer's autoload runs and loaded instrumented right after.
2. The instrumentation reads the file with `nikic/php-parser` and inserts the probes by byte
   offset into the original source, without reprinting the code. No line changes its number, and
   `__FILE__` and `__DIR__` keep pointing at the original file.
3. Only the probes executed during each test count, the same window PHPUnit gives Xdebug: code
   executed in a data provider does not count as covered.
4. What the autoloader never sees comes instrumented too: a PHP file of the target directory that
   the code loads by its path, with `include` or `require` (a Laravel migration, a routes file, a
   test that `require`s the file), opens the instrumented copy. A `file://` stream wrapper,
   registered only in the processes that run tests, makes that swap; every other file operation
   goes on to PHP's own wrapper. It only steps in when the target directory has files the
   autoloader does not load (those that declare no named class, interface, trait or enum, apart
   from Composer's `files` key). `--includes` and `--no-includes` force the choice.
5. At the end of each test, Sonda records which probes it executed, with the file, the size
   (`#[Small]`, `#[Medium]`, `#[Large]`), the duration and whether it failed. Equal sets of probes
   are stored once, because the tests of a suite nearly all go through the same code.
6. At the end of each process, the run is written. The command merges the runs of all workers and
   builds the report with php-code-coverage. With `--covered-by`, every covered line and branch
   carries the names of the tests that executed it, as with Xdebug: in the HTML, hovering a line
   shows those tests, and a test that failed shows in red.

## Blade views

In a Laravel project (one with `artisan`), the Blade views join the measurement along with the
`<source>`: every `.blade.php` in `resources/views`, and in the `resources/views` directories of
the packages and modules kept inside the project (outside `vendor`, `node_modules`, `storage`,
`public`, `tests` and `bootstrap`), appears in the reports with its directive and echo lines
(`@if`, `@foreach`, `{{ }}`, `<x-component>`) covered or not, and with the branches of its
decisions (`@if`, `@unless`, `@isset`, `@foreach`, `@forelse`, `@switch`, `??` and `?->` in
echos), in the same strict mode as PHP: an `@if` without `@else` needs its false case tested. The
`@endif` line is not executable. A view enters the `--changed` verdict as a whole, like a method:
a changed line in a view demands the lines and branches of the whole view. `--views=a,b` adds other
view directories; `--no-blade` leaves the views out.

How it works: the first time a test boots the application, Sonda compiles every view with the
application's own Blade compiler (custom directives included), instruments the compiled PHP and
writes the result where Laravel will include it, in a compiled-views directory of this process
alone, pointed at by `VIEW_COMPILED_PATH` in the environment (Laravel's default configuration reads
that variable). The project's cache in `storage/framework/views` is not touched. The lines of the
compiled PHP map back to the template's lines: a marker inserted before each construct survives
compilation and tells which line it came from. The instrumented copy is kept newer than the
template, so Laravel does not compile it again without probes; with `--parallel`, each worker has
its own directory, and the per-token subdirectory that Laravel's parallel testing uses is created
before the copy.

What Blade and Livewire write around the template does not count as a decision, because its
outcome depends on how the view is rendered and no test controls it: the
`if (ExtendBlade::isRenderingLivewireComponent())` guard Livewire puts on every `@if`, `@unless`
and `@foreach`; the saving and restoring of `$component`, `$attributes` and `$message` around a
component tag and `@error`; the `$component->shouldRender()`; the loops, `if` and `??` with which
`@props`, `@aware` and `@livewire` handle `$__key` and the other double-underscore variables. The
lines of that code are still measured; the template's decisions (`@error`, `@forelse`, `@if`...)
too.

For php-code-coverage, which only counts branches of declared functions, each view is the function
`view`: the copy of the compiled code that the report analyses in place of the template declares
that function around the code (with the `use` statements removed, which do not fit in a function),
and the probes of the view carry that name. It is what appears in the functions table of the HTML.

## Branch semantics

A branch is one outcome of a decision: the entry of a function, the body of `if`, `elseif`,
`else`, a loop, `case`, `catch` and `finally`, the right operand of `&&`, `||`, `and`, `or` and
`??`, the arms of `?:` and `match`, and the non-null value of `?->`.

In strict mode, which is the default:

- An empty body of `if`, `elseif`, `else` and loops is an outcome like any other: it gets a probe
  without a line, and counts as a branch.
- `fn&(...) => expr` becomes the closure it abbreviates, `function &(...) use (...) { probe; return expr; }`,
  with the variables of the expression captured by value as the arrow function does; `&get => expr`
  becomes `&get { probe; return expr; }`. The returned reference stays intact.
- Every `if` without `else` gets a synthetic `else`: both outcomes need to be tested. Xdebug counts
  basic blocks, so an `if` without `else` whose condition is always true gives 100% there and not
  in Sonda.
- `?->` counts both sides, null and non-null.
- The left operand of `&&`, `||`, `and` and `or` has to decide alone at least once (the short
  circuit, when the right one does not even run), as Xdebug counts. Not in `??`: its left side may
  be an undefined key, which only that operator reads without a warning.

Sonda does not count the implicit "no arm matched" branch of `match`, which Xdebug counts and no
test reaches when the arms cover every case of an enum.

Sonda also does not count the fallback jump Xdebug sees in a call to a native *frameless* function
(`in_array`, `max`, `preg_replace`, `str_contains`...) made inside a namespace without
`use function`: it is a detail of the opcode, not a decision of the code, and a `use function`
only to silence the coverage stops being needed.

A method called as a *first-class callable* (`$this->method(...)`) shows in Xdebug as a loose
function named after the method, with the hits, while the class method stays at zero; Sonda
credits the method. `tools/compare.php` merges the two entries before comparing.

## Known limits

- Blade views: only the project's `resources/views` directories are found on their own; another
  directory comes in with `--views`.
- A file of Composer's `files` key that another file of the target directory also loads by a direct
  `require` would be declared twice, from the instrumented copy and from its own path. The command
  refuses to run in that case, resolving `__DIR__`, `__FILE__` and `dirname()` in the argument of
  the `require`.
- The line of a `catch` that an exception of another type passed through shows as uncovered (Xdebug
  counts it as executed).
- Tests that read the class file through reflection see the instrumented copy.

## Development

```bash
composer check          # pint, phpstan, rector (dry-run) and the suite
composer format         # rector and pint
XDEBUG_MODE=coverage php -d zend_extension=xdebug.so vendor/bin/pest --path-coverage --coverage-text
XDEBUG_MODE=coverage php -d zend_extension=xdebug.so vendor/bin/pest --mutate --class='Sonda\Probe'
php tools/phpunit-for-installed-coverage.php   # phpunit.xml without the compatibility classes of the other php-code-coverage version, for the 100% coverage
php tools/lint-corpus.php vendor 1   # instruments a whole directory and checks that the PHP is still valid
php tools/compare.php xdebug.cov sonda.cov -v   # compares two .cov files line by line and method by method
sonda compare <output directory>   # at the root of a project: Sonda, Xdebug with paths and the comparison, with summary.txt
```

`sonda compare` (`tools/compare-with-xdebug.sh`; `--processes`, `--batch` and `--xdebug` become
the variables below) runs the suite twice, with Sonda and with Xdebug in `--parallel`, and leaves
`sonda.cov`, `xdebug.cov`, `compare.txt` and `summary.txt` in the given directory; extra arguments
go to the runner (`tools/compare-with-xdebug.sh output tests/Feature/Orders` measures only that
directory). It generates its own `phpunit.xml` with `pathCoverage="true"`, because ParaTest does
not accept `--path-coverage`, and with absolute paths. PHPUnit's `--coverage-filter` does not
restrict anything: it adds to the `<source>` of `phpunit.xml`. On a big suite, `BATCH=80` runs the
Xdebug side in batches of up to 80 tests, one process per batch and `PROCESSES` queues with their
own database (`${DB_DATABASE}_q<N>`), and merges the `.cov` files at the end: that is how the
Laravel suite above finished.

All new code ships with 100% of lines and branches, measured with Xdebug, and with mutation testing
leaving no survivors beyond the equivalent ones. The tests in `tests/Feature` run `bin/sonda` and
`bootstrap.php` over a sample project, with PHPUnit and with ParaTest. See
[CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT.
