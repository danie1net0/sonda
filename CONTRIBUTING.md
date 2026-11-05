# Contributing

## Running the checks

```bash
composer install
vendor/bin/pest                      # the suite
vendor/bin/pint --test               # code style
vendor/bin/phpstan analyse           # static analysis
composer check                       # pint, phpstan, rector (dry-run) and the suite, as CI runs them
```

Measure changed code with Sonda in strict mode. The helper creates a temporary copy of the
instrumenter in a separate namespace, so even Sonda's bootstrap classes can be instrumented
and the tests can reset their own probe state without erasing the measurement:

```bash
composer test:coverage
# Or choose the base and reports explicitly:
php tools/self-coverage.php --changed=origin/master --affected --html=build/coverage
```

Mutation testing on the class you touched uses Pest's mutation plugin with PCOV enabled:

```bash
XDEBUG_MODE=off vendor/bin/pest tests/Unit/ProbeTest.php --mutate --parallel --class='Sonda\Probe'
```

## What a change needs

- Tests for the change, in the test file of the class (one test file per class, mirroring `src/`).
- 100% of lines and 100% of branches on the code you added or changed, measured with the command
  above. Code that looks unreachable is removed, not excluded from coverage.
- Mutation testing on the touched classes with no survivors beyond equivalent mutants.
- Pint and PHPStan clean.
- Braces on every control body; no `else`, `elseif` or nested `if`. Use guard clauses and
  separate logical steps with blank lines. Compose returned arrays by assignment. The
  `CodingStandardsTest` checks the executable source, tests and PHP tools; fixture strings
  deliberately exercise every PHP syntax the instrumenter supports.
- Commit messages in the [Conventional Commits](https://www.conventionalcommits.org/) format
  (`fix:`, `feat:`, `perf:`, `docs:`...). semantic-release decides the next version from them: a
  `fix` is a patch, a `feat` is a minor, a `BREAKING CHANGE` footer is a major.

## Reporting a bug

Open an issue with:

- the output of `sonda --version`;
- the PHP version, the test runner (PHPUnit, Pest, ParaTest, `artisan test`) and its version;
- the exact command you ran and what it printed;
- when the instrumentation itself is wrong (a file that fails to load, a line counted wrongly), a
  minimal PHP file that reproduces it. `php tools/lint-corpus.php <directory> 1` instruments a whole
  directory and checks that the PHP is still valid, which is the fastest way to find such a file.
