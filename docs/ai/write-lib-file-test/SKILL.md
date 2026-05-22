---
name: write-lib-file-test
description: Write or update one matching PHPUnit file for one top-level Ultiorganizer lib file. Use when extending the per-file lib test model incrementally and keep the work scoped to a single catalog entry.
metadata:
  short-description: Write one per-file lib PHPUnit test
---

# Write Lib File Test

Use this skill when the task is to add or update the matching PHPUnit test for one top-level `../ultiorganizer/lib/*.php` file.

Always read these references first:

- `docs/lib-tests.md`
- `docs/phpunit.md`
- `docs/fixtures.md`
- `docs/lib-test-pitfalls.md`
- `config/lib-test-catalog.json`

To use code coverage to find uncovered branches in the target file and confirm new assertions exercised them, use `docs/ai/use-coverage-for-tests/SKILL.md`.

## Goal

Maintain strict one-test-file-per-lib traceability.

For the target file, the stable artifacts are:

- one catalog entry
- one matching PHPUnit file
- one declared strategy
- optional triage status

## Workflow

1. Locate the catalog entry for the target `lib/*.php` file.
2. Reuse the deterministic matching path from the catalog instead of inventing a new file name.
3. Start from the declared `LegacyApp` load profile and only widen it if the first-pass setup is clearly insufficient.
4. Add at least one meaningful assertion.
5. Record fragile or unclear areas in notes instead of overfitting the first version.
6. Run the narrowest validation command first.

## Validation

Use the smallest relevant command first:

- `./test:filter baseline-default <pattern>`
- `./test:unit`
- `./test:integration`

Use these maintenance commands as needed:

- `./libtest:catalog-refresh`
- `./libtest:missing`
- `./libtest:scaffold --lib-file <name>`

## Boundaries

- Do not broaden one file task into a repo-wide lib test regeneration.
- Do not split one lib file across multiple PHPUnit files.
- Do not hide ambiguity by writing weak assertions that say nothing useful.
