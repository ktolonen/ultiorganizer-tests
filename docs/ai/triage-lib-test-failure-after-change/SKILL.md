---
name: triage-lib-test-failure-after-change
description: Classify a failing per-file lib test after a code change. Use when a matching test for a top-level `lib/*.php` file fails and the next step is to decide between product regression, stale expectation, test bug, or ambiguity.
metadata:
  short-description: Triage one failing per-file lib test
---

# Triage Lib Test Failure After Change

Classify one failing per-file lib test after an SUT change. Follow `docs/lib-test-triage.md`.

## Workflow

1. Read the changed SUT file, the matching test, and the output of `./libtest:run --lib-file <f>`.
2. Choose one outcome: `implementation_regression`, `expected_behavior_changed`, `test_bug`, or `ambiguous`.
3. Justify the choice in concrete code terms. If it is `ambiguous`, name the missing evidence.
4. Record the outcome in the catalog `triage_notes` and set `triage_status` to `triaged`.

## Rules

- Keep the scope to one file unless changed files are directly coupled.
- Never silently turn a regression into a test update.
- Use `expected_behavior_changed` only when the diff clearly shows intent.
