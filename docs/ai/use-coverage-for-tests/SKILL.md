---
name: use-coverage-for-tests
description: Use harness code coverage to target and validate PHPUnit test creation for top-level Ultiorganizer lib files. Use when deciding which branches to assert, finding uncovered functions or lines in a target lib file, or confirming new assertions exercised the intended code. Invoke automatically at the start of any lib test work and after each batch of new tests — do not prompt the user.
metadata:
  short-description: Use coverage to target PHPUnit tests
---

# Use Coverage For Tests

Use automatically whenever authoring or extending a test for a top-level `lib/*.php` file. Do not ask the user first.

## Targets

Per-file line and function targets live in `config/lib-test-catalog.json` (`targets`, with optional per-entry `line_target` or `function_target`). `./libtest:coverage` reports them, so you do not need to read the catalog.

Coverage comes only from the in-process `unit` and `integration` suites, scoped to the SUT `lib/` tree minus vendored directories. Code outside `lib/` (entrypoints, `localization.php`) never appears, so an entrypoint-coupled wrapper is not a fixable gap.

## Command

```sh
./libtest:coverage --lib-file <lib-filename>
```

It emits JSON like:

```json
{
  "lib_file": "team.functions.php",
  "line":      { "pct": 69.6, "covered": 638, "total": 917, "meets_target": false },
  "functions": { "pct": 70.8, "covered": 46,  "total": 65,  "meets_target": false },
  "uncovered": ["TeamMove"],
  "partial":   [{ "name": "TeamListAll", "pct": 64.3, "covered": 18, "total": 28 }],
  "targets":   { "line_pct": 90, "function_pct": 100 }
}
```

- `uncovered`: functions never entered. Add tests for these first.
- `partial`: functions entered but not fully covered. Deepen these next.

## Loop

1. Read `docs/lib-test-pitfalls.md`, then run the command.
2. Test `uncovered` functions, then `partial` ones.
3. Rerun the command after each batch, and repeat until both `meets_target` values are `true`.
4. If a branch cannot be reached in-process (`exit()`/`die()`, entrypoint coupling), record it in the catalog `triage_notes` and move on. See `docs/lib-test-deep-coverage.md`.

## Rules

- Coverage shows what ran, not what is correct. Every covered branch needs a value assertion; follow the assertion-quality rules in `AGENTS.md`.
- Do not widen `<source>` in `phpunit.xml.dist`.
- Do not turn a test task into an SUT refactor. Surface the refactor need instead.
