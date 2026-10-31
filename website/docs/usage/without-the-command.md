---
title: Without the command
sidebar_position: 6
description: Use the bootstrap directly in PHPUnit, Pest or ParaTest, configured by environment variables.
---

`bootstrap.php` works directly in PHPUnit, Pest and ParaTest, configured by environment variables;
it is what the command does underneath. It serves a runner the command does not know, or a CI
step that wants to drive the pieces itself.

## The bootstrap

Pass the bootstrap to the runner with the variables set. With Sonda installed by Composer:

```bash
SONDA_AUTOLOAD=vendor/autoload.php SONDA_TARGET=app SONDA_EXCLUDE=app/Providers \
SONDA_CACHE=/tmp/sonda/cache SONDA_OUT=/tmp/sonda/runs SONDA_STRICT=1 SONDA_FAST=1 \
php artisan test --parallel --bootstrap=vendor/ddr/sonda/bootstrap.php
```

With a clone, the bootstrap is `/opt/sonda/bootstrap.php`. Each process (every ParaTest worker
included) saves its own run in `SONDA_OUT`; use a new directory for each measurement.

### Variables

Lists are separated by `PATH_SEPARATOR` (`:` on Linux and macOS).

| Variable | Required | Meaning |
|---|---|---|
| `SONDA_AUTOLOAD` | yes | `vendor/autoload.php` of the measured project |
| `SONDA_TARGET` | yes | instrumented directories |
| `SONDA_CACHE` | yes | directory of the instrumented files |
| `SONDA_OUT` | yes | directory of the runs, one file per process; use a new one for each measurement |
| `SONDA_EXCLUDE` | no | directories left out |
| `SONDA_ONLY` | no | the only files measured |
| `SONDA_STRICT=1` | no | strict branch semantics |
| `SONDA_FAST=1` | no | local reference per function: cheaper probes |
| `SONDA_CHAIN` | no | the project's original bootstrap, when it is not just the autoload |
| `SONDA_VIEWS` | no | directories of Blade views to measure |
| `SONDA_INCLUDES=1` | no | instruments the files of the target loaded by their path (`include`/`require`) too |

## The report

`report.php` merges the runs of a directory and writes a php-code-coverage `.cov` file, which
serves any other format:

```bash
php vendor/ddr/sonda/report.php /tmp/sonda/runs app coverage.cov app/Providers
```

Its arguments: the runs directory, the source directory, the output `.cov` file and, optionally,
excluded directories.

```
usage: report.php <runs directory> <source directory> <output .cov file> [excluded directory]...
```
