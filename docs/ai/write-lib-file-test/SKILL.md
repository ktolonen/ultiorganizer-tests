---
name: write-lib-file-test
description: Write or update one matching PHPUnit file for one top-level Ultiorganizer lib file. Use when extending the per-file lib test model incrementally and keep the work scoped to a single catalog entry.
metadata:
  short-description: Write one per-file lib PHPUnit test
---

# Write Lib File Test

Add or update the one matching PHPUnit file for one top-level `../ultiorganizer/lib/*.php` file.

Read first: `docs/lib-tests.md`, `docs/lib-test-pitfalls.md`, `docs/fixtures.md`, the file's entry in `config/lib-test-catalog.json`, and the SUT source of the functions under test.

## Workflow

1. Use the catalog's `test_path` and `load_profile`. Never invent a filename or split one lib file across test files. If the file is missing, run `./libtest:scaffold --lib-file <f>`.
2. Widen the load profile only when the declared one clearly falls short.
3. Drive the work with `docs/ai/use-coverage-for-tests/SKILL.md`.
4. Pin values from the fixture, and anchor every negative assertion with a positive contrast (see `AGENTS.md`).
5. Validate with `./libtest:run --lib-file <f>`, then `./test:matrix` before pushing.

Note that `./test:filter` runs only the `integration` suite. For unit lib tests, use `./libtest:run` or `./test:unit --test-filter <pattern>`.

## Boundaries

- Keep the task to one file; do not regenerate tests repo-wide.
- Record unreachable or ambiguous areas in `triage_notes` rather than writing weak assertions.
