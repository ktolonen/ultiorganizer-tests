# Design: `./libtest:coverage` tool and updated coverage skill

Date: 2026-06-22

## Goal

Give Claude a single CLI command that produces fresh per-file coverage data as
machine-readable JSON, and update the `use-coverage-for-tests` skill to drive a
tight write-test → check-coverage loop without prompting the user.

## Context

All 43 lib files have test files. The campaign goal is ≥80% line coverage and
100% function coverage per file. The existing HTML coverage report already
computes per-function coverage (using PCOV line data cross-referenced against
PHP function definitions), but it is only readable in a browser. The clover.xml
does not expose standalone PHP functions as "methods" — they show up only as
statement lines. The tool bridges that gap by computing function coverage
programmatically from clover data + PHP source.

## Coverage targets

Targets are stored in `config/lib-test-catalog.json`:

- Top-level `"targets"` block holds global defaults:
  ```json
  "targets": { "line_pct": 80, "function_pct": 100 }
  ```
- Each catalog entry may override with `"line_target"` and/or
  `"function_target"` fields. If absent, the global default applies.

The tool reads the effective target per file and includes it in JSON output so
the skill never needs to read the catalog directly.

## The `./libtest:coverage` command

### Invocation

```sh
./libtest:coverage --lib-file <lib-filename>
```

Example:

```sh
./libtest:coverage --lib-file team.functions.php
```

### Behaviour

1. Runs the matching test via the same path as `./libtest:run --lib-file <name>`
   (full container run with coverage enabled).
2. Reads the resulting `clover.xml` for the target file.
3. Reads the PHP source file to extract function definitions (regex on
   `^function \w+(`).
4. For each function, finds the first tracked statement line inside the function
   body in clover and checks its execution count — if > 0 the function is
   covered.
5. Computes line coverage from clover statement counts.
6. Reads effective targets from the catalog entry (falling back to global).
7. Emits JSON to stdout and exits.

### JSON output schema

```json
{
  "lib_file": "team.functions.php",
  "line": {
    "pct": 69.6,
    "covered": 638,
    "total": 917,
    "meets_target": false
  },
  "functions": {
    "pct": 70.8,
    "covered": 46,
    "total": 65,
    "meets_target": false
  },
  "uncovered": [
    "TeamMove",
    "AddTeamProfileUrl",
    "RemoveTeamProfileUrl",
    "TeamsToCsv",
    "TeamAbbreviation"
  ],
  "partial": [
    { "name": "TeamListAll", "pct": 64.3, "covered": 18, "total": 28 },
    { "name": "AddPlayer",   "pct": 71.0, "covered": 22, "total": 31 }
  ],
  "targets": { "line_pct": 80, "function_pct": 100 }
}
```

Field semantics:

| Field | Meaning |
|---|---|
| `line.meets_target` | `line.pct >= targets.line_pct` |
| `functions.meets_target` | `functions.covered == functions.total` |
| `uncovered` | Functions whose first statement was never executed (count == 0) |
| `partial` | Functions entered at least once but with at least one uncovered statement line in the body |

### Implementation location

- New Python module `scripts/libtest_coverage.py` — contains the clover
  parsing, PHP source parsing, and JSON assembly logic.
- New entrypoint `./libtest:coverage` — thin shell wrapper delegating to
  `scripts/harness.py` with a `libtest-coverage` subcommand, consistent with
  the existing `libtest:*` entrypoints.
- `scripts/harness.py` gains a `libtest-coverage` subcommand that calls the
  module after running the test.

### Function detection algorithm

```
for each line in PHP source:
    if line matches ^function \w+(:
        record (function_name, line_number)

sorted_stmt_lines = sorted line numbers present in clover for this file

for each (func_name, func_line) in functions:
    next_func_line = next function's line_number, or EOF
    first_stmt = first sorted_stmt_line where func_line < stmt_line < next_func_line
    covered = first_stmt exists AND clover count[first_stmt] > 0
```

This matches how PHPUnit's HTML report computes per-function coverage.

## Catalog schema additions

Two new optional fields per entry in `config/lib-test-catalog.json`:

```json
{
  "lib_file": "team.functions.php",
  ...existing fields...,
  "line_target": 80,
  "function_target": 100
}
```

Both fields are optional. The `libtest:catalog-refresh` command preserves
existing values when refreshing the catalog and sets neither field on new
entries (the tool falls back to global defaults).

The global `"targets"` block is added at the top level of the catalog JSON,
alongside `"version"`, `"sut_path"`, `"naming"`, and `"entries"`.

## Updated `use-coverage-for-tests` skill

The skill at `docs/ai/use-coverage-for-tests/SKILL.md` is updated to replace
the manual HTML/clover workflow with a command-driven loop.

### New workflow (replaces old steps 2–6)

1. Run `./libtest:coverage --lib-file <name>` and parse the JSON output.
2. Write tests targeting functions in `uncovered` first (zero → covered), then
   functions in `partial` (deepen to push line coverage toward target).
3. After each batch of new tests, run `./libtest:coverage --lib-file <name>`
   again and re-read the JSON — no user prompt needed.
4. Stop when both `line.meets_target` and `functions.meets_target` are `true`.
5. If a function cannot be covered (auth-only `die()` / `exit()` branch with no
   happy path), add a triage comment in the test file and skip it — do not ask
   the user.

### Automatic invocation rule

The skill fires automatically (without user prompt) whenever:

- The user asks to deepen, add, or improve tests for a lib file.
- The user asks to improve lib test coverage generally.
- Claude is mid-task on a lib test file and needs to know what is still missing.

The skill does **not** require the user to explicitly say "use the coverage
tool" — Claude invokes it as a standard step of lib test work.

## What is not in scope

- A coverage gate that fails harness runs (Approach B from brainstorming) — not
  included; can be added later once per-file targets are established.
- Changes to the PCOV setup, `phpunit.xml.dist`, or the coverage pipeline.
- SUT refactors to unlock `die()`-heavy branches.
- Changes to the `report:html` rendering or `coverage.json` aggregate output.
