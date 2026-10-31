---
name: Bug report
about: Something Sonda measured or did wrong
labels: bug
---

**Versions**

- `sonda --version`:
- PHP:
- Test runner and version (PHPUnit, Pest, ParaTest, `artisan test`):

**Command**

```bash
sonda test ...
```

**Expected**

**Actual**

Paste the output, including any error message.

**Reproduction**

When the instrumentation itself is wrong (a file fails to load, a line or branch is counted
wrongly), a minimal PHP file that reproduces it:

```php
<?php
```
