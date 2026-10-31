---
title: How it works
sidebar_position: 7
description: Probes by byte offset, a cache of instrumented copies, an autoloader ahead of Composer's, one run per worker.
---

Sonda rewrites nothing you see: the instrumented copies live in a cache outside the project, the
original files keep their line numbers, and the report comes from php-code-coverage.

1. **An autoloader ahead of Composer's.** The bootstrap registers an autoloader before Composer's.
   A class of the target directory is loaded from an instrumented copy, cached by path,
   modification time and instrumenter version. The files of Composer's `files` key that are inside
   the target (helpers) are claimed before Composer's autoload runs and loaded instrumented right
   after.
2. **Probes by byte offset.** The instrumentation reads the file with `nikic/php-parser` and
   inserts the probes by byte offset into the original source, without reprinting the code. No
   line changes its number, and `__FILE__` and `__DIR__` keep pointing at the original file. A probe
   is one assignment into an array (`Hits::$current[<file id>][<probe id>] = 1`); with the default
   fast mode, a local reference is bound once per function, so each probe is a single assignment.
3. **The window of each test.** Only the probes executed during each test count, the same window
   PHPUnit gives Xdebug: code executed in a data provider does not count as covered.
4. **Files loaded by their path.** What the autoloader never sees comes instrumented too: a PHP
   file of the target directory that the code loads by its path, with `include` or `require` (a
   Laravel migration, a routes file, a test that `require`s the file), opens the instrumented copy.
   A `file://` stream wrapper, registered only in the processes that run tests, makes that swap;
   every other file operation goes on to PHP's own wrapper. It only steps in when the target
   directory has files the autoloader does not load (those that declare no named class, interface,
   trait or enum, apart from Composer's `files` key). `--includes` and `--no-includes` force the
   choice.
5. **What each test hit.** At the end of each test, Sonda records which probes it executed, with
   the file, the size (`#[Small]`, `#[Medium]`, `#[Large]`), the duration and whether it failed.
   Equal sets of probes are stored once, because the tests of a suite nearly all go through the
   same code. This is what `--affected` and `--covered-by` read.
6. **One run per process, one report.** At the end of each process (every ParaTest worker
   included), the run is written. The command merges the runs of all workers and builds the report
   with php-code-coverage. With `--covered-by`, every covered line and branch carries the names of
   the tests that executed it, as with Xdebug: in the HTML, hovering a line shows those tests, and
   a test that failed shows in red.

## Cost

A probe is an array assignment, so the instrumented code runs at nearly the speed of the original:
on the Laravel suite of the [comparison](comparison-with-xdebug.md), 174s with Sonda against 178s
without coverage, and 40s against 42s with 10 ParaTest workers. Peak memory per worker, across the
10 workers of `tests/Feature/Filament` in that suite, with the include wrapper on: 52 MB. Up to
1.14.2, that wrapper held memory on every file operation, and the peak reached 107 MB per worker.

## Where the pieces are

Cache and runs stay outside the project, in `$XDG_CACHE_HOME/sonda` or `~/.cache/sonda`, in a
directory private to the user (mode 0700): the instrumented copies run as code, so nobody else may
write there. Sonda refuses a cache directory owned by another user or open to others. The test map
of `--affected` lives there too, unless `--map` moves it.
