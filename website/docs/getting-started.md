---
title: Getting started
sidebar_position: 2
description: Requirements, installation with Composer or a clone, and the first run.
---

Sonda installs as a development dependency, or as one clone per machine, and runs from the root of
the project it measures.

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

Updating is `git -C /opt/sonda pull`.

`sonda --version` (or `-V`) prints the version of the sonda that is running: the one Composer
installed, or the tag of the checkout.

```bash
$ vendor/bin/sonda --version
sonda 1.16.0
```

## First run

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

With `--changed` or `--files`, the output also says what the verdict looked at and what is left
uncovered, and the command exits with an error when something is:

```
Coverage: lines 118/120 (98.33%), branches 61/63 (96.83%)
Of what changed: lines 40/42 (95.24%), branches 18/20 (90.00%)
Uncovered:
app/Actions/Scheduling/BookAppointmentAction.php: lines 57-58; branches 57-60
app/Models/ScheduleResource.php: branches 272
```

When nothing is left:

```
Coverage: lines 121/121 (100.00%), branches 78/78 (100.00%)
The changed files are 100% covered.
```

`--text` lists the coverage of each file after the total, and `--html=<dir>` writes the HTML report
with every line and branch marked. All the options are in the [CLI reference](usage/cli-reference.md).

## Where things are kept

Cache and runs stay outside the project, in `$XDG_CACHE_HOME/sonda` or `~/.cache/sonda`, in a
directory private to the user (mode 0700): the instrumented copies run as code, so nobody else may
write there. Sonda refuses a cache directory owned by another user or open to others.
