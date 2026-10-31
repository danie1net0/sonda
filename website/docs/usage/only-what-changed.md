---
title: Only what changed
sidebar_position: 3
description: Measure only the files a branch changed and demand 100% of them.
---

`--changed` and `--files` narrow the measurement to a set of files and turn the result into a
verdict: anything left uncovered in them fails the run.

## `--changed=<ref>`

```bash
sonda test --changed=origin/master
```

Measures only the files of the `<source>` changed since the `merge-base` with the reference:
committed, modified and new. In a shallow clone, with no common history to find the `merge-base`,
the reference itself is the base, which is what CI hands over (`CI_MERGE_REQUEST_DIFF_BASE_SHA` on
GitLab, see [GitLab](../ci/gitlab.md)).

The verdict demands 100% **of what changed**: the lines the diff added or replaced and the methods
and functions they fall in, whole; a new file is demanded whole. An old gap in another method of
the same file does not fail. A Blade view enters the verdict as a whole, like a method: a changed
line in a view demands the lines and branches of the whole view.

When something is left, the command lists it and exits with an error:

```
Coverage: lines 118/120 (98.33%), branches 61/63 (96.83%)
Of what changed: lines 40/42 (95.24%), branches 18/20 (90.00%)
Uncovered:
app/Actions/Scheduling/BookAppointmentAction.php: lines 57-58; branches 57-60
app/Models/ScheduleResource.php: branches 272
```

Each line of `Uncovered:` names the file, the lines never executed (as ranges) and the branches
never taken (by the lines of the decision). When nothing is left, the exit code is zero and the
command prints `What changed is 100% covered.`

When no file of the `<source>` changed, there is nothing to measure and the command says so:

```
No changed file inside <source>: nothing to measure.
```

This is the intended use in CI: a job that measures only what the merge request touched and
demands 100% of it. With `--code-quality` and `--cobertura`, GitLab marks the covered lines in the
MR diff and points, on the line itself, at what still needs a test.

## `--files=a,b`

```bash
sonda test --files=app/Models/Order.php,app/Actions/PlaceOrder.php
```

Demands the listed files whole, even when they also changed. The verdict and the output are the
same as with `--changed`; when nothing is left, the command prints `The changed files are 100% covered.`

## Floors for the whole project

`--min-lines=<n>` and `--min-branches=<n>` fail the run when the coverage of the whole project is
below n%. They are a global floor, on top of the 100% of what changed:

```bash
sonda test --changed=origin/master --min-lines=99 --min-branches=98
```

With a floor, Sonda instruments and measures the whole `<source>` even with `--changed` or
`--files`, whose verdict keeps looking only at their files; the printed total is the project's. When
a floor is not met:

```
Coverage below the minimum: lines 98.70% < 99.00%
```

Two consequences of a floor to keep in mind:

- When no file of the `<source>` changed, the whole project is still measured, so the floor can be
  checked: `No changed file inside <source>: with a coverage floor, the whole project is measured.`
- [`--affected`](affected-tests.md) is set aside, because only the whole suite can answer for the
  whole project: `--affected ignored: with a coverage floor, the whole suite runs.`
