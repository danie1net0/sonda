---
title: Only the affected tests
sidebar_position: 4
description: Run only the tests that execute what changed, by the test map each run writes.
---

`--affected` turns the verdict of `--changed` or `--files` into a run of seconds: only the test
files that execute the changed code run.

## `--affected`

```bash
sonda test --changed=origin/master --affected
```

Every run writes, outside the project, a map of which files of the `<source>` the tests of each
test file executed. With `--affected`, together with `--changed` or `--files`, Sonda runs only the
test files that execute what changed, plus the test files that changed. The tests covering the
changed code are all of those, so the verdict stays the same, in seconds instead of the whole
suite. The command says what it is running:

```
Running only the affected tests: tests/Unit/Actions/PlaceOrderTest.php
```

With many test files, it counts them instead: `Running only the affected tests: 11 of 12 test files`.

`--affected` needs `--changed` or `--files`: they say what changed. Alone, it is refused with
`The option --affected needs --changed or --files: they say what changed.`

## When the whole suite runs anyway

The map does not answer for everything, and then the whole suite runs, measuring the whole project
so the map learns. The command states the reason before running:

```
Running the whole suite and measuring the whole project: this project has no test map yet; this run creates it.
```

The reasons:

| Reason printed | When |
|---|---|
| `this project has no test map yet; this run creates it` | the first run, or the map file is missing |
| `no known test runs <path>` | a file of the `<source>` that no known test executes changed: a new file, or one never executed |
| `<path> changed and is neither a <source> file nor a test` | any file outside the `<source>` and the tests changed (configuration, routes, migrations, `composer.lock`, a test helper), because any test may read it |

Two other cases set `--affected` aside without a map lookup:

- With a coverage floor (`--min-lines`, `--min-branches`), the whole suite runs anyway:
  `--affected ignored: with a coverage floor, the whole suite runs.`
- With the tests to run already given to the runner (`sonda test --changed=... --affected tests/Unit`):
  `--affected ignored: the tests to run were given.`

A stale map never leaves an affected test out: every run rewrites what the test files that ran
execute, and a file of the `<source>` the map does not know makes the whole suite run. What it can
do is run extra tests.

## `--map=<file>`

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
    - vendor/bin/sonda test --changed="$CI_MERGE_REQUEST_DIFF_BASE_SHA" --affected --map=.sonda/tests.map
```

## `--covered-by`

```bash
sonda test --html=coverage/html --covered-by
```

Every covered line and branch carries the names of the tests that executed it, as with Xdebug; in
the HTML, they show on hovering the line, and a test that failed shows in red. It weighs on a big
suite: with 5,615 tests, the HTML went from 123 MB to 1.3 GB and the report's memory from 300 MB
to 870 MB.

Both `--affected` and `--covered-by` depend on Sonda reading the file and the size of each test.
When it cannot for some test, the coverage is the same, but the command warns that those two may
come out incomplete:

```
Warning: Sonda could not read the file or the size of some test; the coverage is the same, but --affected and --covered-by may come out incomplete: <problem>
```
