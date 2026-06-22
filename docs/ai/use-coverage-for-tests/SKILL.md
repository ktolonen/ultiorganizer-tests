---
name: use-coverage-for-tests
description: Use harness code coverage to target and validate PHPUnit test creation for top-level Ultiorganizer lib files. Use when deciding which branches to assert, finding uncovered functions or lines in a target lib file, or confirming new assertions exercised the intended code. Invoke automatically at the start of any lib test work and after each batch of new tests — do not prompt the user.
metadata:
  short-description: Use coverage to target PHPUnit tests
---

# Use Coverage For Tests

Use this skill whenever you are authoring or extending PHPUnit tests for a top-level
`lib/*.php` file. Invoke it **automatically** — the user does not need to ask for it.

## When to invoke

- User asks to deepen, add, or improve tests for a lib file.
- User asks to improve lib test coverage generally.
- You are mid-task on a lib test file and need to know what is still missing.

## Coverage targets

Per file:
- Line coverage ≥ **80%**
- Function coverage = **100%** (every function called at least once on the happy path)

Targets are stored in `config/lib-test-catalog.json` (`targets` block + optional per-entry overrides).
The `./libtest:coverage` command reads and reports them — you never need to read the catalog directly.

## What coverage covers

Coverage comes only from the in-process `unit` and `integration` suites (PCOV).
HTTP-driven `export`, `api`, `smoke`, and `crawl` suites yield no coverage.

Coverage scope is the SUT's `lib/` tree minus vendored directories.
Lines exercised outside `lib/` (page entrypoints, `localization.php`, bootstrap) never appear —
do not treat an entrypoint-coupled wrapper as an uncovered-and-fixable line when the real code
lives outside `lib/`.

## The command

```sh
./libtest:coverage --lib-file <lib-filename>
```

This runs the matching test suite with coverage and emits JSON to stdout:

```json
{
  "lib_file": "team.functions.php",
  "line":      { "pct": 69.6, "covered": 638, "total": 917, "meets_target": false },
  "functions": { "pct": 70.8, "covered": 46,  "total": 65,  "meets_target": false },
  "uncovered": ["TeamMove", "AddTeamProfileUrl"],
  "partial":   [{ "name": "TeamListAll", "pct": 64.3, "covered": 18, "total": 28 }],
  "targets":   { "line_pct": 80, "function_pct": 100 }
}
```

| Field | Meaning |
|---|---|
| `uncovered` | Functions whose first statement was never executed — add tests |
| `partial` | Functions entered but body not fully covered — deepen |
| `line.meets_target` | `line.pct >= targets.line_pct` |
| `functions.meets_target` | `functions.covered == functions.total` |

## Workflow

1. Pick the target `lib/*.php` file and its matching test (see `write-lib-file-test/SKILL.md` and the catalog).
2. Run `./libtest:coverage --lib-file <name>` and read the JSON.
3. Write tests targeting functions in `uncovered` first (zero → covered on the happy path), then
   functions in `partial` (deepen the body to push line coverage toward the target).
   Read `docs/lib-test-pitfalls.md` before writing any test.
4. After each batch of new tests, run `./libtest:coverage --lib-file <name>` again — **do not ask
   the user** — and re-read the JSON.
5. Repeat steps 3–4 until both `line.meets_target` and `functions.meets_target` are `true`.
6. If a function cannot be covered (auth-only `die()` / `exit()` with no testable happy path),
   add a triage comment in the test file noting the gap and move on — **do not ask the user**.

## Rules

- Never add an assertion-free test just to colour a line. Coverage tells you what code ran,
  not whether the behaviour is correct.
- Prefer branches that map to deterministic, fixture-backed behaviour. Defer branches that only
  run via `die()` / `exit()` / redirect or hidden entrypoint bootstrap; record those as triage
  notes (see `docs/lib-test-deep-coverage.md`).
- Do not widen the `<source>` scope in `phpunit.xml.dist`.
- Do not hand-edit `.runtime/` or `reports/`.
- Do not convert a coverage-guided test task into an SUT refactor. Surface refactor needs per
  `docs/lib-test-deep-coverage.md` rather than forcing coverage.

## Also read

- `docs/lib-tests.md` — naming conventions, catalog, incremental commands
- `docs/lib-test-pitfalls.md` — concrete gotchas: shim persistence, cache flush, assertContains types
- `docs/lib-test-deep-coverage.md` — what blocks deep coverage and what to do about it
- `docs/phpunit.md` — PHPUnit mechanics, coverage artifact locations
