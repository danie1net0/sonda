---
title: GitLab
sidebar_position: 11
description: A merge-request job that measures only what the MR changed, marks the diff and demands 100%.
---

The job below is the one a real Laravel project runs on every merge request. It measures only the
files the MR touched, demands 100% of them, and hands GitLab two reports that mark the MR diff.

## The `coverage` job

```yaml
coverage:
  extends: test      # the job that installs dependencies, prepares the database and runs the suite
  retry: 0
  script:
    - |
      HEAD_SHA="${CI_MERGE_REQUEST_SOURCE_BRANCH_SHA:-$CI_COMMIT_SHA}"
      git fetch --quiet --depth=1 origin "$CI_MERGE_REQUEST_DIFF_BASE_SHA" "$HEAD_SHA"
      # With GIT_DEPTH 1 there is no merge-base: Sonda uses the MR's own base sha as the base of the diff.
      php vendor/bin/sonda test --changed="$CI_MERGE_REQUEST_DIFF_BASE_SHA" \
        --min-lines=99 --min-branches=84 \
        --html=coverage/html --cobertura=coverage/cobertura.xml \
        --code-quality=coverage/code-quality.json --processes=3
  artifacts:
    when: always
    expire_in: 1 week
    paths:
      - coverage/html/
    reports:
      coverage_report:
        coverage_format: cobertura
        path: coverage/cobertura.xml
      codequality: coverage/code-quality.json
  only:
    - merge_requests
```

What each piece does:

- **`extends: test`** reuses the project's test job: dependencies from the cache, the database
  prepared, `APP_ENV=testing`. Sonda is in `vendor/` as a dev dependency (`composer require --dev
  ddr/sonda`), so nothing is installed on the runner.
- **`GIT_DEPTH: 1` and the two shas.** With a shallow clone there is no common history to find the
  `merge-base`, so the job fetches the MR's base and head commits with `--depth=1` and passes the
  base sha itself to `--changed`: Sonda then diffs against that commit. That is what
  `CI_MERGE_REQUEST_DIFF_BASE_SHA` is for, and it is why the job runs `only: merge_requests`.
- **`--changed`** measures only the `<source>` files the MR changed and fails the job if a changed
  line or a touched method is left uncovered; a new file is demanded whole. See
  [Only what changed](../usage/only-what-changed.md).
- **`--min-lines` and `--min-branches`** are a floor for the whole project, on top of the 100% of
  what changed. With them, Sonda measures the whole `<source>`, so the printed total is the
  project's. Set them to where the project stands and raise them as coverage grows; the branch
  floor is lower in this project because its Blade views still have most of their branches
  untested.
- **`--cobertura`** is what GitLab reads to mark covered and uncovered lines in the MR diff
  (`coverage_report`).
- **`--code-quality`** turns every uncovered stretch of lines and every untaken branch of what the
  verdict demands into a Code Quality finding, shown on the line itself in the MR diff
  (`codequality`).
- **`--html`** is kept as a browsable artifact for a week; `when: always` keeps the reports when
  the job fails, which is when they matter.
- **`--processes=3`** goes to ParaTest; pick it for the runner.

The verdict's output in the job log looks like this when something is left:

```
Coverage: lines 118/120 (98.33%), branches 61/63 (96.83%)
Of what changed: lines 40/42 (95.24%), branches 18/20 (90.00%)
Uncovered:
app/Actions/Scheduling/BookAppointmentAction.php: lines 57-58; branches 57-60
app/Models/ScheduleResource.php: branches 272
```

## Faster: only the affected tests

`--affected` runs only the test files that execute what changed, by a map each run writes. Keep the
map in the CI cache with `--map` so it carries from one job to the next:

```yaml
coverage:
  cache:
    key: sonda-test-map
    paths: [.sonda/tests.map]
  script:
    - vendor/bin/sonda test --changed="$CI_MERGE_REQUEST_DIFF_BASE_SHA" --affected --map=.sonda/tests.map
```

`--affected` is set aside when a floor is given (`--min-lines`, `--min-branches`), because only
the whole suite can answer for the whole project. See
[Only the affected tests](../usage/affected-tests.md).

## A companion: mutation testing of what changed

Coverage says a line ran; it does not say a test checked its behaviour. The same project runs a
`mutation` job next to `coverage`, with Pest's mutation plugin on the files the MR changed:

```yaml
mutation:
  extends: test
  retry: 0
  allow_failure: true
  script:
    - |
      HEAD_SHA="${CI_MERGE_REQUEST_SOURCE_BRANCH_SHA:-$CI_COMMIT_SHA}"
      git fetch --quiet --depth=1 origin "$CI_MERGE_REQUEST_DIFF_BASE_SHA" "$HEAD_SHA"
      FILES=$(git diff --name-only --diff-filter=AMR "$CI_MERGE_REQUEST_DIFF_BASE_SHA" "$HEAD_SHA" -- app \
        | grep -E '\.php$' | paste -sd, -) || true
      if [ -z "$FILES" ]; then
        echo "No app/ file changed: nothing to mutate."
        exit 0
      fi
      php vendor/bin/pest --mutate --parallel --no-cache --covered-only \
        --path="$FILES" --min=100 --ignore-min-score-on-zero-mutations
  only:
    - merge_requests
```

Mutation testing needs a coverage driver on the runner (PCOV is enough) to map tests to lines; a
surviving mutant is a test that executes the line without checking what it does, while an
uncovered line is the `coverage` job's business. `allow_failure: true` lets the job calibrate on
real MRs before it blocks them. See the
[Pest documentation on mutation testing](https://pestphp.com/docs/mutation-testing).
