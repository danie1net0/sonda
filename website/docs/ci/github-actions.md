---
title: GitHub Actions
sidebar_position: 12
description: A pull-request workflow that measures only what the PR changed and keeps the HTML report.
---

No extension is needed on the runner: Sonda comes with `composer install`, and `coverage: none`
keeps Xdebug and PCOV out of the way.

## The workflow

```yaml
name: coverage

on:
  pull_request:

jobs:
  coverage:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0   # the whole history, so Sonda finds the merge-base with the base branch

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none   # no Xdebug, no PCOV: Sonda instruments the source

      - run: composer install --prefer-dist --no-progress --no-interaction

      - run: vendor/bin/sonda test --changed=origin/${{ github.base_ref }} --html=coverage/html

      - uses: actions/upload-artifact@v4
        if: always()
        with:
          name: coverage-html
          path: coverage/html
```

What each piece does:

- **`fetch-depth: 0`** fetches the whole history, so `--changed=origin/<base branch>` finds the
  `merge-base` between the PR and its base and diffs from there: committed, modified and new files
  of the `<source>`. With the default shallow checkout there is no common history; then pass the
  base commit itself (`--changed=${{ github.event.pull_request.base.sha }}`) after fetching it.
- **`coverage: none`** in `setup-php` leaves Xdebug and PCOV disabled. Sonda does not need them,
  and an Xdebug loaded in coverage mode would only slow the suite down.
- **`--changed`** measures only the files the PR changed and fails the job when a changed line or
  a touched method is left uncovered; a new file is demanded whole. The output lists what is
  missing. See [Only what changed](../usage/only-what-changed.md).
- **`--html`** writes the report; `if: always()` uploads it also when the job failed, which is
  when it matters.

For a floor on the whole project, add `--min-lines=<n> --min-branches=<n>`; to run only the tests
that execute what changed, add `--affected --map=.sonda/tests.map` and keep `.sonda/tests.map` with
`actions/cache` between runs. See [Only the affected tests](../usage/affected-tests.md).

## Clover for other tools

Services that read Clover (Codecov, Coveralls and the like) take `--clover=coverage/clover.xml`
from the same run; `--cov=<file>` writes php-code-coverage's own format, which serves any other.
