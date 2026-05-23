---
name: write-phpunit-test
description: Write or update PHPUnit tests for the Ultiorganizer test harness. Use when adding coverage for harness PHP behavior, DB-backed helper behavior, or deterministic smoke checks. Choose the smallest fitting suite first, prefer extending existing tests over creating redundant files, and keep fixture and case dependencies explicit.
metadata:
  short-description: Write PHPUnit tests for the harness
---

# Write PHPUnit Test

Write or update PHPUnit tests in this repository while fitting the current harness structure.

Always read these references first:

- `docs/phpunit.md`
- `docs/fixtures.md`
- `docs/smoke.md`
- `docs/architecture.md`
- `docs/lib-test-pitfalls.md` (when writing lib file tests)

## Purpose

Use this skill when the task is to add or update PHPUnit coverage for:

- `tests/Unit`
- `tests/Integration`
- `tests/Smoke`

For the dedicated top-level `lib/*.php` one-file-per-lib workflow, prefer `docs/ai/write-lib-file-test/SKILL.md`.

To target and validate `unit`/`integration` test work with code coverage, use `docs/ai/use-coverage-for-tests/SKILL.md`.

This skill is for writing tests, not for broad crawl-plan changes. If the real requirement is broader HTTP discovery, authenticated route coverage, or anonymous security path checks, prefer `crawl` configuration instead of forcing that behavior into PHPUnit.

## Suite Selection

Choose the smallest suite that matches the behavior under test.

- `unit`: pure or mostly pure PHP behavior that does not need the disposable database
- `integration`: DB-backed helper behavior that depends on the loaded schema and fixture pack
- `smoke`: deterministic public page rendering checks driven through HTTP

Default rules:

- Prefer `unit` when the code can be asserted directly.
- Prefer `integration` when correctness depends on fixture-backed DB state.
- Prefer `smoke` only for stable page-level checks with a small explicit allowlist.
- Do not add crawl-style coverage to PHPUnit.

## Repo-Specific Rules

- Reuse existing test files when the new coverage belongs to the same subject area.
- Create a new test file only when the subject does not fit cleanly into an existing one.
- Keep tests deterministic against the baseline fixture unless the task clearly requires a new fixture pack.
- Do not hand-edit `.runtime/` or `reports/`.
- Do not change the production SUT checkout for test-only setup.

## Workflow

1. Inspect existing tests in the target suite before adding new ones.
2. Identify the narrowest behavior that should be asserted.
3. Confirm whether the test depends on baseline fixture data or needs a new explicit fixture addition.
4. Add or update the PHPUnit test in the most relevant existing file when practical.
5. Keep assertions specific and readable instead of asserting large opaque payloads.
6. Run the smallest relevant harness command first.
7. If needed, run the broader case or quick command to confirm no regression in neighboring coverage.

## Integration Test Rules

- Treat the baseline fixture as the primary source of DB-backed test data.
- If a new integration assertion needs additional data, add the smallest possible fixture change and keep it deterministic.
- Make fixture dependencies obvious in the test names and assertions.
- Prefer asserting behavior through existing helper functions rather than duplicating SQL logic in tests.

## Smoke Test Rules

- Keep smoke checks small and stable.
- Prefer adding a new page entry to `smoke_pages` when the smoke suite should cover another public page.
- Use `tests/Smoke` for the actual smoke assertion behavior, not for growing page-specific one-off logic.
- Failures should remain diagnosable through page id, query, status, response snippet, and log excerpt.

## Assertion Rules

- Use focused assertions with clear failure messages where the default output would be vague.
- Prefer checking the specific behavior that matters instead of asserting every field in a large array.
- Avoid overfitting to irrelevant fixture details.
- Keep test names descriptive enough that the first failed test in the report is useful on its own.

## Validation Commands

Use the smallest relevant command first:

- `./test:unit`
- `./test:integration`
- `./test:smoke`
- `./test:filter baseline-default <pattern>`

Use broader validation only when needed:

- `./test:quick`
- `./test:case baseline-default`

## Boundaries

- Do not convert a PHPUnit task into a crawl-plan task unless the requested behavior is genuinely crawl-oriented.
- Do not add a new matrix case just to cover one more assertion.
- Do not introduce nondeterministic data or time-sensitive assertions without controlling the setup.
- Do not broaden a small test task into unrelated fixture or reporting refactors.
