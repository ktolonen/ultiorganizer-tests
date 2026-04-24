---
name: classify-lib-file-for-testing
description: Classify one top-level Ultiorganizer lib file into an initial per-file test strategy. Use when adding a new `lib/*.php` file to the catalog or when re-checking whether a file should start in `unit`, `integration`, or a guard/bootstrap-oriented strategy.
metadata:
  short-description: Classify one lib file for per-file testing
---

# Classify Lib File For Testing

Use this skill when the task is to classify one top-level `../ultiorganizer/lib/*.php` file for the per-file test catalog.

Always read these references first:

- `docs/lib-tests.md`
- `docs/phpunit.md`
- `docs/architecture.md`

## Goal

Produce a narrow first-pass classification for one file:

- target suite: `unit` or `integration`
- strategy: `direct_helper`, `fixture_backed`, `bootstrap_guard`, `bootstrap_runtime`, or another small file-specific variant
- starting `LegacyApp` load profile

This is not a request to write a full test or to redesign the whole catalog.

## Workflow

1. Read the target lib file and identify whether it is mostly pure logic, DB-backed helper behavior, or guard/bootstrap behavior.
2. Check the existing catalog entry in `config/lib-test-catalog.json`.
3. Keep the first-pass choice lightweight. Favor the smallest credible setup.
4. Record unclear dependency risks in notes instead of forcing a deep strategy too early.

## Repo Rules

- Only classify top-level `lib/*.php` files, not nested vendor-style libraries under `lib/`.
- Prefer `unit` unless the behavior clearly depends on the disposable database or fixture-backed reads.
- Use guard/bootstrap strategies when direct inclusion behavior is the meaningful first check.
- Keep later checkpoint refinements separate from the initial classification.
