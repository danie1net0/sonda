**What this changes**

**Checklist**

- [ ] Tests for the change, in the test file of each touched class
- [ ] 100% of lines and branches on what changed (`XDEBUG_MODE=coverage vendor/bin/pest --path-coverage --coverage-text --min=100`)
- [ ] Pint and PHPStan clean (`composer check`)
- [ ] Commits in the Conventional Commits format (`fix:`, `feat:`, `perf:`, `docs:`...)
