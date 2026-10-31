# Sonda

[Português](README.pt-BR.md) · [Documentation](https://danie1net0.github.io/sonda) · [Packagist](https://packagist.org/packages/ddr/sonda)

Line and branch coverage for PHP by instrumenting the source: no Xdebug, no PCOV, the suite runs
at its normal speed, and every decision has to show both outcomes.

Sonda inserts probes into the code before loading it, the way Istanbul does for JavaScript. A probe
is one assignment into an array; there is no opcode interception and no path enumeration, which is
what makes Xdebug slow and heavy. On a Laravel suite of 4,514 tests: 178s without coverage, 174s
with Sonda, 671s with Xdebug counting lines only, and Xdebug with `--path-coverage` did not finish
in an hour. The numbers were validated against Xdebug path coverage on that whole suite.

## Install

PHP 8.4+, PHPUnit 12 or Pest 4.

```bash
composer require --dev ddr/sonda
```

## Use

```bash
vendor/bin/sonda test                               # the whole suite, the whole <source> of phpunit.xml
vendor/bin/sonda test --changed=origin/master       # only the files the branch changed; fails if any is left uncovered
vendor/bin/sonda test --changed=origin/master --affected   # and only the tests that execute them
vendor/bin/sonda test --html=coverage/html --cobertura=coverage/cobertura.xml
```

```
Coverage: lines 12118/12118 (100.00%), branches 4857/4857 (100.00%)
Of what changed: lines 86/86 (100.00%), branches 31/31 (100.00%)
The changed files are 100% covered.
```

Branches are counted in a strict mode by default: an `if` without `else` needs both outcomes
tested, `?->` counts the null side, and `&&`/`||` must short-circuit at least once. Blade views are
measured too, line and branch.

The documentation has the [getting started guide](https://danie1net0.github.io/sonda/docs/getting-started),
the [CLI reference](https://danie1net0.github.io/sonda/docs/usage/cli-reference), the
[branch semantics](https://danie1net0.github.io/sonda/docs/branch-semantics), the
[comparison with Xdebug](https://danie1net0.github.io/sonda/docs/comparison-with-xdebug) and recipes for
[GitLab](https://danie1net0.github.io/sonda/docs/ci/gitlab) and
[GitHub Actions](https://danie1net0.github.io/sonda/docs/ci/github-actions).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports go to the
[issues](https://github.com/danie1net0/sonda/issues) with the output of `sonda --version`.

## License

[MIT](LICENSE.md)
