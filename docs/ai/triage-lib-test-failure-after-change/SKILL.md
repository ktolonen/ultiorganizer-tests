---
name: triage-lib-test-failure-after-change
description: Classify a failing per-file lib test after a code change. Use when a matching test for a top-level `lib/*.php` file fails and the next step is to decide between product regression, stale expectation, test bug, or ambiguity.
metadata:
  short-description: Triage one failing per-file lib test
---

# Triage Lib Test Failure After Change

Use this skill when a matching per-file lib test fails after a user changes a top-level `../ultiorganizer/lib/*.php` file.

Always read these references first:

- `docs/lib-test-triage.md`
- `docs/lib-tests.md`
- `config/lib-test-catalog.json`

## Required Output

Produce one classification for the file:

- `implementation_regression`
- `expected_behavior_changed`
- `test_bug`
- `ambiguous`

Keep the output file-specific.

## Workflow

1. Read the changed SUT file and the matching PHPUnit file.
2. Check the failure output from the narrowest targeted run.
3. Decide whether the product behavior regressed, the expectation became stale, the test is wrong, or the situation is not yet defensible.
4. Explain the reasoning in concrete code-level terms.
5. If the result is ambiguous, name the missing evidence instead of guessing.

## Repo Rules

- Keep the scope to one file unless multiple changed files are directly coupled.
- Prefer `./libtest:triage-status` to understand current triage state before rewriting tests.
- Do not silently convert a regression into a test update.
- Do not classify as `expected_behavior_changed` unless the code change clearly supports that reading.
