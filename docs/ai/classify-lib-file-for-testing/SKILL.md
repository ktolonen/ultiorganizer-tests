---
name: classify-lib-file-for-testing
description: Classify one top-level Ultiorganizer lib file into an initial per-file test strategy. Use when adding a new `lib/*.php` file to the catalog or when re-checking whether a file should start in `unit`, `integration`, or a guard/bootstrap-oriented strategy.
metadata:
  short-description: Classify one lib file for per-file testing
---

# Classify Lib File For Testing

Give one top-level `../ultiorganizer/lib/*.php` file a first-pass catalog classification. Read `docs/lib-tests.md` first.

## Output

- suite: `unit` or `integration`
- strategy: `direct_helper`, `fixture_backed`, `bootstrap_guard`, or `bootstrap_runtime`
- starting `LegacyApp` load profile

## Rules

- Classify only top-level files, never vendored directories under `lib/`.
- Prefer `unit` unless the behavior depends on fixture-backed DB reads.
- Use a guard or bootstrap strategy when the direct-include behavior is the meaningful check.
- Pick the smallest credible setup, and put dependency risks in `notes` instead of over-planning.
