---
name: use-coverage-for-tests
description: Use harness code coverage to target and validate PHPUnit test creation for top-level Ultiorganizer lib files. Use when deciding which branches to assert, finding uncovered lines in a target lib file, or confirming new assertions actually exercised the intended code. Coverage comes from the in-process unit and integration suites only.
metadata:
  short-description: Use coverage to target PHPUnit tests
---

# Use Coverage For Tests

Use this skill when authoring or extending PHPUnit tests and you want coverage to guide what to assert and to confirm new assertions actually exercise the target code.

Always read these references first:

- `docs/phpunit.md` (the "Code coverage" section: PCOV opt-in, scope, artifacts)
- `docs/lib-tests.md`
- `docs/lib-test-deep-coverage.md`
- `docs/lib-test-pitfalls.md`

This skill is the workflow layer. It does not restate the harness coverage mechanics; those live in `docs/phpunit.md`.

## What Coverage Covers

- Coverage is produced only by the in-process suites: `unit` and `integration`. The HTTP-driven `export`, `api`, `smoke`, and `crawl` suites yield no coverage. Frame test work around the in-process suites, not "unit only".
- Coverage scope is the SUT's `lib/` tree minus vendored third-party directories. Lines exercised outside `lib/` (page entrypoints, `localization.php`, and other bootstrap files) never appear, even when your test runs them. This is the same boundary `docs/lib-test-deep-coverage.md` calls out as a frontier blocker — do not treat an entrypoint-coupled wrapper as uncovered-and-fixable when the real code lives outside `lib/`.

## Where To Read Coverage

After a run, artifacts live under `reports/cases/<case-id>/<run-id>/coverage/`:

- `coverage/html/index.html` — per-file browsable report; the reliable place to see which lines in one file are covered.
- `coverage/clover.xml` — per-file line data for programmatic reads. File entries use absolute container paths like `/workspace/.runtime/cases/baseline-default/sut/lib/<file>.php`, so match on the `lib/<file>.php` suffix, not a host path.
- `coverage/coverage.txt` — overall summary only (Classes / Methods / Lines %). It has no per-file or per-line detail. Do not read it to find uncovered lines.
- `coverage/coverage.json` — overall percent / covered / total, surfaced by `report:html`.

`coverage/` is wiped at the start of every run. Always rerun the relevant suite before consulting coverage; never trust a stale directory or a previous run's numbers.

## Workflow

1. Pick the target `lib/*.php` file and its matching test (see `write-lib-file-test/SKILL.md` and the catalog).
2. Run the in-process suite that owns the test to produce fresh coverage:
   - `./test:unit` for a unit-suite target
   - `./test:integration` for an integration-suite target
   - `./test:filter baseline-default <pattern>` to iterate fast on one test; it runs the integration suite scoped to matched tests and still produces coverage for what ran.
3. Open `coverage/html/index.html` (or parse `clover.xml`) for the target file and read which lines and branches are uncovered.
4. Add focused assertions that exercise the meaningful uncovered branches — deterministic, fixture-backed paths first.
5. Rerun the same suite and confirm the intended lines moved from uncovered to covered.
6. Stop when the meaningful behavior of the file is asserted, not when a number is hit.

## Rules

- Do not chase the overall coverage percentage. It is low by design; per-file lib testing aims for local depth on the file under test.
- Coverage tells you what code ran, not whether behavior is correct. A covered line still needs a real assertion — never add an assertion-free test just to color a line.
- Prefer covering branches that map to deterministic, fixture-backed behavior. Defer branches that only run via `die()`/`exit()`/redirect or hidden entrypoint bootstrap; record those as triage notes instead of forcing brittle coverage (see `docs/lib-test-deep-coverage.md`).
- Do not widen the `<source>` scope in `phpunit.xml.dist` to chase coverage of non-`lib/` code.
- Do not hand-edit `.runtime/` or `reports/`.

## Boundaries

- This skill guides test authoring with coverage; it is not for changing the coverage pipeline, the PCOV setup, or the `report:html` rendering.
- Do not convert a coverage-guided test task into an SUT refactor. If a file genuinely needs a refactor before it is testable, surface that per `docs/lib-test-deep-coverage.md` rather than forcing coverage.
