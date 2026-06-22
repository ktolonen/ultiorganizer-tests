# libtest:coverage Tool Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `./libtest:coverage --lib-file <name>` — a command that runs a lib file's tests and emits machine-readable JSON showing per-function coverage and whether the 80%/100% targets are met — then update the `use-coverage-for-tests` skill to use it automatically.

**Architecture:** A new host-side Python module (`scripts/libtest_coverage.py`) does the clover parsing and PHP source analysis. A new `lib-test-coverage` subcommand in `scripts/harness.py` runs the test (reusing the existing `run_case()` path), locates the resulting `clover.xml`, feeds it to the module, and prints JSON to stdout. The `use-coverage-for-tests` skill is updated to call this command at the start and after each batch of new tests, looping until both `meets_target` fields are `true`.

**Tech Stack:** Python 3, `xml.etree.ElementTree` (stdlib), `re` (stdlib), existing `harness.py` patterns, PHPUnit/PCOV clover XML format.

## Global Constraints

- Follow the existing `libtest:*` entrypoint pattern: thin bash wrapper → `scripts/harness.py <subcommand>`.
- All new Python code lives on the HOST side (not in the container). The container produces `clover.xml`; the host reads it.
- Container-format paths (`/workspace/reports/...`) are converted to host paths via the existing `report_host_path()` in `harness.py`.
- `build_lib_test_catalog()` must preserve all existing per-entry fields when refreshing the catalog.
- No changes to `container_runner.py`, `phpunit.xml.dist`, or the PCOV setup — coverage collection for `unit`/`integration` suites is already automatic.
- JSON output goes to stdout; all diagnostic/progress output goes to stderr.
- The skill file lives at `docs/ai/use-coverage-for-tests/SKILL.md`.

---

## File Map

| Action | Path | Responsibility |
|--------|------|----------------|
| Modify | `config/lib-test-catalog.json` | Add top-level `targets` block; per-entry `line_target`/`function_target` optional fields |
| Modify | `scripts/harness.py` | Add global `COVERAGE_TARGETS` constant, preserve target fields in `lib_test_entry()`, update `build_lib_test_catalog()`, add `cmd_lib_test_coverage()` and its parser entry |
| Create | `scripts/libtest_coverage.py` | Parse clover XML + PHP source; compute per-function coverage; build JSON output |
| Create | `./libtest:coverage` | Thin bash entrypoint |
| Modify | `docs/ai/use-coverage-for-tests/SKILL.md` | Replace manual HTML workflow with `./libtest:coverage` loop |
| Modify | `CLAUDE.md` | Add `./libtest:coverage` to stable entrypoints |

---

### Task 1: Add coverage targets to the catalog

**Files:**
- Modify: `scripts/harness.py` (near line 17 for constants; `lib_test_entry()` at line 190; `build_lib_test_catalog()` at line 240)
- Modify: `config/lib-test-catalog.json` (via `./libtest:catalog-refresh`)

**Interfaces:**
- Produces: `catalog["targets"]` dict with `line_pct` and `function_pct` keys; optional `line_target`/`function_target` per entry (preserved on refresh)

- [ ] **Step 1: Add the default targets constant to harness.py**

  After the existing path constants (around line 22 in harness.py), add:

  ```python
  COVERAGE_TARGETS = {"line_pct": 80, "function_pct": 100}
  ```

- [ ] **Step 2: Preserve target fields in lib_test_entry()**

  In `lib_test_entry()`, the `entry` dict is built from scratch on every refresh. Two new optional fields must round-trip through refresh. In the block that builds `entry` (around line 214), add after `"notes"`:

  ```python
  }
  # Preserve per-file coverage target overrides if they were set previously.
  for field in ("line_target", "function_target"):
      value = (existing_entry or {}).get(field)
      if value is not None:
          entry[field] = value
  ```

  Place this immediately after the closing brace of the `entry = {...}` dict literal, before the `if sut_path:` block.

- [ ] **Step 3: Add targets block to build_lib_test_catalog()**

  In `build_lib_test_catalog()`, the returned dict currently has keys `version`, `sut_path`, `naming`, `entries`. Add `targets`. Replace the return statement:

  ```python
  return {
      "version": 1,
      "sut_path": normalize_sut_path(sut_path),
      "naming": {
          "subject_id": "lib filename without the .php suffix",
          "test_class_suffix": "LibTest",
          "test_path_pattern": "tests/<Unit|Integration>/Lib/<DerivedClassName>LibTest.php",
      },
      "targets": dict(COVERAGE_TARGETS),
      "entries": entries,
  }
  ```

- [ ] **Step 4: Refresh the catalog**

  ```bash
  ./libtest:catalog-refresh
  ```

  Expected output JSON: `entry_count` matches previous count, no entries lost. Verify:

  ```bash
  python3 -c "
  import json
  d = json.load(open('config/lib-test-catalog.json'))
  print('targets:', d['targets'])
  print('first entry keys:', list(d['entries'][0].keys()))
  "
  ```

  Expected:
  ```
  targets: {'line_pct': 80, 'function_pct': 100}
  first entry keys: ['lib_file', 'lib_path', 'subject_id', 'test_suite', 'test_path', 'test_class', 'strategy', 'load_profile', 'test_path_exists', 'status', 'triage_status', 'triage_notes', 'notes', 'sut_path']
  ```

  (No `line_target`/`function_target` in entries yet — those only appear when explicitly set.)

- [ ] **Step 5: Commit**

  ```bash
  git add scripts/harness.py config/lib-test-catalog.json
  git commit -m "Add coverage targets to catalog (global defaults + per-entry override support)"
  ```

---

### Task 2: Create scripts/libtest_coverage.py

**Files:**
- Create: `scripts/libtest_coverage.py`

**Interfaces:**
- Consumes: `clover_path: Path`, `source_path: Path`, `catalog_entry: dict` (from `load_lib_test_catalog()`), `global_targets: dict` (`{"line_pct": int, "function_pct": int}`)
- Produces: `build_coverage_json(clover_path, source_path, catalog_entry, global_targets) -> dict` — the JSON-serialisable dict matching the spec schema

- [ ] **Step 1: Create the module with all four functions**

  Create `scripts/libtest_coverage.py`:

  ```python
  """Host-side per-file coverage analysis for libtest:coverage."""

  from __future__ import annotations

  import re
  import xml.etree.ElementTree as ET
  from pathlib import Path


  def parse_clover_for_lib_file(clover_path: Path, lib_filename: str) -> dict[int, int]:
      """Return {line_num: execution_count} for the named lib file from a clover XML.

      Matches on the lib/<filename> suffix so container paths work unchanged.
      Returns an empty dict if the file is not found in the clover data.
      """
      try:
          root = ET.parse(clover_path).getroot()
      except (ET.ParseError, OSError):
          return {}
      suffix = f"lib/{lib_filename}"
      for file_el in root.iter("file"):
          name = file_el.get("name", "")
          if name.endswith(suffix):
              return {
                  int(ln.get("num")): int(ln.get("count", 0))
                  for ln in file_el.iter("line")
                  if ln.get("type") == "stmt"
              }
      return {}


  def parse_php_functions(source_path: Path) -> list[tuple[str, int]]:
      """Return [(function_name, line_number), ...] for top-level PHP functions.

      Matches lines of the form:  function FuncName(
      Line numbers are 1-based, matching clover's convention.
      """
      pattern = re.compile(r"^function\s+(\w+)\s*\(")
      results = []
      try:
          lines = source_path.read_text(encoding="utf-8", errors="replace").splitlines()
      except OSError:
          return []
      for i, line in enumerate(lines, 1):
          m = pattern.match(line)
          if m:
              results.append((m.group(1), i))
      return results


  def _function_coverage(
      line_counts: dict[int, int],
      functions: list[tuple[str, int]],
  ) -> tuple[list[str], list[dict]]:
      """Compute uncovered and partial function lists.

      A function is 'covered' if the first tracked stmt line inside its body has
      execution count > 0.  A function is 'partial' if it is covered but has at
      least one stmt line inside its body with count == 0.
      """
      sorted_stmts = sorted(line_counts.keys())
      uncovered: list[str] = []
      partial: list[dict] = []

      for idx, (fname, fline) in enumerate(functions):
          next_fline = functions[idx + 1][1] if idx + 1 < len(functions) else float("inf")
          body_stmts = [ln for ln in sorted_stmts if fline < ln < next_fline]
          if not body_stmts:
              # No tracked statements in body — treat as uncovered.
              uncovered.append(fname)
              continue
          first_count = line_counts[body_stmts[0]]
          if first_count == 0:
              uncovered.append(fname)
          else:
              zero_stmts = sum(1 for ln in body_stmts if line_counts[ln] == 0)
              if zero_stmts > 0:
                  total_body = len(body_stmts)
                  covered_body = total_body - zero_stmts
                  partial.append(
                      {
                          "name": fname,
                          "pct": round(covered_body / total_body * 100, 1),
                          "covered": covered_body,
                          "total": total_body,
                      }
                  )

      return uncovered, partial


  def build_coverage_json(
      clover_path: Path,
      source_path: Path,
      catalog_entry: dict,
      global_targets: dict,
  ) -> dict:
      """Build the full coverage JSON dict for one lib file."""
      lib_file = catalog_entry["lib_file"]
      line_target = catalog_entry.get("line_target", global_targets.get("line_pct", 80))
      fn_target = catalog_entry.get("function_target", global_targets.get("function_pct", 100))

      line_counts = parse_clover_for_lib_file(clover_path, lib_file)
      functions = parse_php_functions(source_path)

      # Line coverage
      total_stmts = len(line_counts)
      covered_stmts = sum(1 for c in line_counts.values() if c > 0)
      line_pct = round(covered_stmts / total_stmts * 100, 1) if total_stmts else 0.0

      # Function coverage
      total_fns = len(functions)
      uncovered, partial = _function_coverage(line_counts, functions)
      covered_fns = total_fns - len(uncovered)
      fn_pct = round(covered_fns / total_fns * 100, 1) if total_fns else 0.0

      return {
          "lib_file": lib_file,
          "line": {
              "pct": line_pct,
              "covered": covered_stmts,
              "total": total_stmts,
              "meets_target": line_pct >= line_target,
          },
          "functions": {
              "pct": fn_pct,
              "covered": covered_fns,
              "total": total_fns,
              "meets_target": covered_fns == total_fns,
          },
          "uncovered": uncovered,
          "partial": partial,
          "targets": {"line_pct": line_target, "function_pct": fn_target},
      }
  ```

- [ ] **Step 2: Verify the module against real data**

  Run this against the existing clover.xml to check the output looks right:

  ```bash
  python3 -c "
  import json
  from pathlib import Path
  import sys
  sys.path.insert(0, 'scripts')
  from libtest_coverage import build_coverage_json

  clover = Path('reports/cases/baseline-default/20260616T081126Z/coverage/clover.xml')
  source = Path('../ultiorganizer/lib/team.functions.php')
  entry = {'lib_file': 'team.functions.php'}
  targets = {'line_pct': 80, 'function_pct': 100}
  result = build_coverage_json(clover, source, entry, targets)
  print(json.dumps(result, indent=2))
  "
  ```

  Expected: JSON with `lib_file: "team.functions.php"`, `line.pct` around 69.6, `functions.covered` around 46/65, non-empty `uncovered` list.

- [ ] **Step 3: Commit**

  ```bash
  git add scripts/libtest_coverage.py
  git commit -m "Add libtest_coverage module: clover parsing and per-function coverage JSON"
  ```

---

### Task 3: Add lib-test-coverage subcommand to harness.py

**Files:**
- Modify: `scripts/harness.py`

**Interfaces:**
- Consumes: `build_coverage_json` from `scripts/libtest_coverage.py`; `run_case()`, `load_lib_test_catalog()`, `select_catalog_entry()`, `report_host_path()`, `COVERAGE_TARGETS` already in `harness.py`
- Produces: `cmd_lib_test_coverage(args)` prints JSON to stdout; returns 0 if both targets met, 1 otherwise

- [ ] **Step 1: Import libtest_coverage at the top of harness.py**

  After the existing imports (around line 15, after `import sys`), add:

  ```python
  from libtest_coverage import build_coverage_json
  ```

  Python automatically adds the running script's directory (`scripts/`) to `sys.path`, so no path manipulation is needed.

- [ ] **Step 2: Add cmd_lib_test_coverage() after cmd_lib_test_run()**

  After the closing of `cmd_lib_test_run()` (around line 1639), add:

  ```python
  def cmd_lib_test_coverage(args: argparse.Namespace) -> int:
      catalog = load_lib_test_catalog()
      entry = select_catalog_entry(catalog, args.lib_file)
      payload = run_case(
          case_id=args.case_id,
          sut_path=args.sut_path,
          suites=entry["test_suite"],
          test_filter=entry["test_class"],
          run_label=args.run_label,
          context_label=args.context_label,
          pr_number=args.pr_number,
          pr_head_ref=args.pr_head_ref,
          pr_base_ref=args.pr_base_ref,
      )
      case_root_str = (payload.get("artifact_paths") or {}).get("case_root", "")
      if not case_root_str:
          raise SystemExit("Coverage: no case_root in run payload; run may have failed before producing artifacts.")
      run_root = report_host_path(case_root_str)
      clover_path = run_root / "coverage" / "clover.xml"
      if not clover_path.is_file():
          raise SystemExit(f"Coverage: clover.xml not found at {clover_path}")
      sut_path_str = entry.get("sut_path") or str(Path(args.sut_path) / "lib" / entry["lib_file"])
      source_path = Path(sut_path_str)
      global_targets = catalog.get("targets", COVERAGE_TARGETS)
      result = build_coverage_json(clover_path, source_path, entry, global_targets)
      print(json.dumps(result, indent=2))
      meets = result["line"]["meets_target"] and result["functions"]["meets_target"]
      return 0 if meets else 1
  ```

- [ ] **Step 3: Register the subparser in build_parser()**

  After the `lib_run` parser block (around line 1737), before `return parser`, add:

  ```python
  lib_coverage = subparsers.add_parser("lib-test-coverage")
  add_common_case_args(lib_coverage)
  lib_coverage.add_argument("--lib-file", required=True)
  lib_coverage.set_defaults(func=cmd_lib_test_coverage)
  ```

- [ ] **Step 4: Smoke-test the subcommand**

  ```bash
  python3 scripts/harness.py lib-test-coverage --lib-file cache.functions.php 2>&1 | tail -5
  ```

  Expected: valid JSON ending with `"targets": {"line_pct": 80, "function_pct": 100}`. `cache.functions.php` is already at 100%/100% so both `meets_target` should be `true`. Exit code should be 0:

  ```bash
  python3 scripts/harness.py lib-test-coverage --lib-file cache.functions.php > /dev/null && echo "exit 0" || echo "exit 1"
  ```

- [ ] **Step 5: Commit**

  ```bash
  git add scripts/harness.py
  git commit -m "Add lib-test-coverage subcommand to harness"
  ```

---

### Task 4: Add ./libtest:coverage entrypoint

**Files:**
- Create: `./libtest:coverage`

**Interfaces:**
- Consumes: `scripts/harness.py lib-test-coverage` subcommand (Task 3)
- Produces: executable entrypoint matching the pattern of `./libtest:run`

- [ ] **Step 1: Create the entrypoint**

  ```bash
  cat > libtest:coverage << 'EOF'
  #!/usr/bin/env bash
  set -euo pipefail
  exec python3 "$(dirname "$0")/scripts/harness.py" lib-test-coverage "$@"
  EOF
  chmod +x "libtest:coverage"
  ```

- [ ] **Step 2: Run an end-to-end test**

  ```bash
  ./libtest:coverage --lib-file cache.functions.php
  ```

  Expected: JSON printed to stdout with `lib_file: "cache.functions.php"`, both `meets_target: true`. The command itself runs the tests inside the container, so this validates the full path.

- [ ] **Step 3: Commit**

  ```bash
  git add "libtest:coverage"
  git commit -m "Add ./libtest:coverage entrypoint"
  ```

---

### Task 5: Update the use-coverage-for-tests skill

**Files:**
- Modify: `docs/ai/use-coverage-for-tests/SKILL.md`

**Interfaces:**
- Consumes: `./libtest:coverage --lib-file <name>` (Task 4)
- Produces: updated skill that Claude invokes automatically without prompting the user

- [ ] **Step 1: Rewrite the skill file**

  Replace the entire content of `docs/ai/use-coverage-for-tests/SKILL.md` with:

  ```markdown
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
  ```

- [ ] **Step 2: Verify the file was written correctly**

  ```bash
  head -5 docs/ai/use-coverage-for-tests/SKILL.md
  ```

  Expected first line: `---`

- [ ] **Step 3: Commit**

  ```bash
  git add docs/ai/use-coverage-for-tests/SKILL.md
  git commit -m "Update use-coverage-for-tests skill to use ./libtest:coverage loop"
  ```

---

### Task 6: Update CLAUDE.md and docs

**Files:**
- Modify: `CLAUDE.md`

**Interfaces:**
- Produces: `./libtest:coverage` listed under Stable entrypoints so future sessions know it exists

- [ ] **Step 1: Add ./libtest:coverage to the stable entrypoints list in CLAUDE.md**

  In the `## Stable entrypoints` section, after the `./libtest:run --lib-file <lib-file>` line, add:

  ```
  - `./libtest:coverage --lib-file <lib-file>`
  ```

- [ ] **Step 2: Run the full matrix to confirm nothing regressed**

  ```bash
  ./test:matrix
  ```

  Expected: all cases pass.

- [ ] **Step 3: Commit**

  ```bash
  git add CLAUDE.md
  git commit -m "Document ./libtest:coverage in stable entrypoints"
  ```
