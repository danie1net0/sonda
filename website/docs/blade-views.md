---
title: Blade views
sidebar_position: 8
description: Laravel views measured line by line and branch by branch, by instrumenting the compiled template.
---

In a Laravel project (one with `artisan`), the Blade views join the measurement along with the
`<source>`.

## What is measured

Every `.blade.php` in `resources/views`, and in the `resources/views` directories of the packages
and modules kept inside the project (outside `vendor`, `node_modules`, `storage`, `public`, `tests`
and `bootstrap`), appears in the reports with its directive and echo lines (`@if`, `@foreach`,
`{{ }}`, `<x-component>`) covered or not, and with the branches of its decisions (`@if`,
`@unless`, `@isset`, `@foreach`, `@forelse`, `@switch`, `??` and `?->` in echos), in the same
strict mode as PHP: an `@if` without `@else` needs its false case tested. The `@endif` line is not
executable.

A view enters the `--changed` verdict as a whole, like a method: a changed line in a view demands
the lines and branches of the whole view.

`--views=a,b` adds other view directories; `--no-blade` leaves the views out. Only the project's
`resources/views` directories are found on their own.

## How it works

The first time a test boots the application, Sonda compiles every view with the application's own
Blade compiler (custom directives included), instruments the compiled PHP and writes the result
where Laravel will include it, in a compiled-views directory of this process alone, pointed at by
`VIEW_COMPILED_PATH` in the environment (Laravel's default configuration reads that variable). The
project's cache in `storage/framework/views` is not touched.

The lines of the compiled PHP map back to the template's lines: a marker inserted before each
construct survives compilation and tells which line it came from. The instrumented copy is kept
newer than the template, so Laravel does not compile it again without probes; with `--parallel`,
each worker has its own directory, and the per-token subdirectory that Laravel's parallel testing
uses is created before the copy.

When the compiled views do not end up in that directory (a configuration that overrides
`VIEW_COMPILED_PATH`), the views are not measured and the process says so on stderr:

```
Sonda: the compiled views are not in <dir> (VIEW_COMPILED_PATH did not reach the configuration): views not measured.
```

A view that does not compile is left out, with its error: `Sonda: the view <view> did not compile and is not measured: <error>`.

## What does not count as a decision

What Blade and Livewire write around the template does not count as a decision, because its
outcome depends on how the view is rendered and no test controls it:

- the `if (ExtendBlade::isRenderingLivewireComponent())` guard Livewire puts on every `@if`,
  `@unless` and `@foreach`;
- the saving and restoring of `$component`, `$attributes` and `$message` around a component tag
  and `@error`;
- the `$component->shouldRender()`;
- the loops, `if` and `??` with which `@props`, `@aware` and `@livewire` handle `$__key` and the
  other double-underscore variables.

The lines of that code are still measured; the template's decisions (`@error`, `@forelse`,
`@if`...) too.

## In the report

For php-code-coverage, which only counts branches of declared functions, each view is the function
`view`: the copy of the compiled code that the report analyses in place of the template declares
that function around the code (with the `use` statements removed, which do not fit in a function),
and the probes of the view carry that name. It is what appears in the functions table of the HTML.
