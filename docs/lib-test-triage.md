# Lib Test Triage

## Purpose

Per-file lib tests are meant to support changed-file maintenance, not just initial authoring.

When a matching lib test fails after a user changes a SUT file, classify the result before deciding whether to change production code or test code.

## Classifications

Use one of these outcomes per lib file:

- `implementation_regression`
- `expected_behavior_changed`
- `test_bug`
- `ambiguous`

The catalog stores the current triage state per file in `config/lib-test-catalog.json`.

## Workflow

1. Identify the changed top-level lib files.
2. Run the narrowest matching PHPUnit command first.
3. Compare the file diff, failure output, and current assertions.
4. Record the triage outcome for the file before broadening the fix.

Use the default changed-file view:

```sh
./libtest:triage-status
```

Use a single-file view when one file is under investigation:

```sh
./libtest:triage-status --lib-file team.functions.php
```

## Decision Rules

Mark the failure as `implementation_regression` when the code change appears to violate previously valid behavior.

Mark the failure as `expected_behavior_changed` when the product behavior clearly changed on purpose and the test expectation is now stale.

Mark the failure as `test_bug` when the assertion or setup is flawed even without a product bug.

Mark the failure as `ambiguous` when the current fixture data, loader assumptions, or failure output are not strong enough to decide safely.

## Checkpoint Use

Checkpoint 1 only introduces the structure and terminology.

Checkpoint 2 should include at least one deliberate triage example so the workflow can be tuned before broad expansion.

Current pilot example:

- `configuration.functions.php` is recorded as `ambiguous` for wrapper-style helpers that depend on broader bootstrap or localization setup than the current per-file lib boundary provides. The first-pass test stays on stable config reads instead.
