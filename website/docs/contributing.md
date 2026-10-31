---
title: Contributing
sidebar_position: 14
description: How to run the checks, what a change needs, and what a bug report needs.
---

Sonda is developed on [GitHub](https://github.com/danie1net0/sonda); issues and pull requests go
there.

## Running the checks

```bash
composer install
vendor/bin/pest                      # the suite
vendor/bin/pint --test               # code style
vendor/bin/phpstan analyse           # static analysis
composer check                       # pint, phpstan, rector (dry-run) and the suite, as CI runs them
```

Coverage is measured with Xdebug path coverage, exactly as CI does, because Sonda's own bootstrap
classes cannot be instrumented by Sonda itself:

```bash
XDEBUG_MODE=coverage vendor/bin/pest --path-coverage --coverage-text --min=100
```

Mutation testing on the class you touched (Pest's mutation plugin, needs a coverage driver):

```bash
XDEBUG_MODE=coverage vendor/bin/pest --mutate --class='Sonda\Probe'
```

Other tools in the repository:

```bash
php tools/phpunit-for-installed-coverage.php   # phpunit.xml without the compatibility classes of the other php-code-coverage version, for the 100% coverage
php tools/lint-corpus.php vendor 1             # instruments a whole directory and checks that the PHP is still valid
php tools/compare.php xdebug.cov sonda.cov -v  # compares two .cov files line by line and method by method
```

The tests in `tests/Feature` run `bin/sonda` and `bootstrap.php` over a sample project, with
PHPUnit and with ParaTest.

## What a change needs

- Tests for the change, in the test file of the class (one test file per class, mirroring `src/`).
- 100% of lines and 100% of branches on the code you added or changed, measured with the command
  above. Code that looks unreachable is removed, not excluded from coverage.
- Mutation testing on the touched classes with no survivors beyond equivalent mutants.
- Pint and PHPStan clean.
- Commit messages in the [Conventional Commits](https://www.conventionalcommits.org/) format
  (`fix:`, `feat:`, `perf:`, `docs:`...). semantic-release decides the next version from them: a
  `fix` is a patch, a `feat` is a minor, a `BREAKING CHANGE` footer is a major.

## Reporting a bug

[Open an issue](https://github.com/danie1net0/sonda/issues/new/choose) with:

- the output of `sonda --version`;
- the PHP version, the test runner (PHPUnit, Pest, ParaTest, `artisan test`) and its version;
- the exact command you ran and what it printed;
- when the instrumentation itself is wrong (a file that fails to load, a line counted wrongly), a
  minimal PHP file that reproduces it. `php tools/lint-corpus.php <directory> 1` instruments a whole
  directory and checks that the PHP is still valid, which is the fastest way to find such a file.

## License

MIT.
