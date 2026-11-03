---
title: Known limits
sidebar_position: 13
description: What Sonda does not do, or does differently from Xdebug.
---

The limits found so far; an issue is welcome for any other.

- **Blade views**: only the project's `resources/views` directories are found on their own;
  another directory comes in with `--views`.
- **A file of Composer's `files` key loaded twice.** A file of Composer's `files` key that another
  file of the target directory also loads by a direct `require` would be declared twice, from the
  instrumented copy and from its own path. The command refuses to run in that case, resolving
  `__DIR__`, `__FILE__` and `dirname()` in the argument of the `require`:

  ```
  app/helpers.php is in Composer's files key and is still loaded by a direct require in app/Boot.php:3: under Sonda it would be declared twice. Drop the require.
  ```

- **A `catch` an exception passed through.** The line of a `catch` that an exception of another
  type passed through shows as uncovered (Xdebug counts it as executed).
- **Reflection on the class file.** Tests that read the class file through reflection see the
  instrumented copy.
- **Closing lines under PHPUnit 13.** php-code-coverage 14 counts as executable some closing lines
  that Sonda places no probe on, such as the `})($value);` of a closure called where it is
  written. Such a line shows as uncovered even when it ran; it is rare, a handful of lines in
  hundreds of files. PHPUnit 11 and 12 do not count it.
- **Memory of the report.** After the suite, the `sonda` process merges the runs of every worker
  and builds the report, and a large suite needs memory there too. It takes the `memory_limit` of
  `<php><ini>` in `phpunit.xml` when that is higher than its own. Without one, a suite of
  thousands of tests may need `php -d memory_limit=-1 vendor/bin/sonda test`.
- **Requirements.** PHP 8.4 or newer; PHPUnit 11.5 (Pest 3), 12 (Pest 4) or 13 (Pest 5) in the
  measured project. Sonda uses PHPUnit's event facade, which is internal, and CI runs the suite on
  the three versions, on the lowest and on the latest of each.
