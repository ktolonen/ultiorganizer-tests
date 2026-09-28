---
name: write-phpunit-test
description: Write or update PHPUnit tests for the Ultiorganizer test harness. Use when adding coverage for harness PHP behavior, DB-backed helper behavior, or deterministic smoke checks. Choose the smallest fitting suite first, prefer extending existing tests over creating redundant files, and keep fixture and case dependencies explicit.
metadata:
  short-description: Write PHPUnit tests for the harness
---

# Write PHPUnit Test

Add or update PHPUnit tests in this harness. For a top-level `lib/*.php` file, use `docs/ai/write-lib-file-test/SKILL.md` instead.

Read first: `docs/phpunit.md`, `docs/fixtures.md`, and `docs/smoke.md` when working on HTTP tests.

## Suite Selection

Pick the smallest suite that can observe the behavior:

- `unit`: pure PHP, no DB
- `integration`: DB-backed helpers against the fixture
- `export`, `api`: machine-readable HTTP contracts
- `smoke`: logic in page files, observed over HTTP. This covers escaping, cross-event refusal, rights checks, and login-gated pages. Add a public page to the allowlist with a `smoke_pages` entry.

Broad route discovery belongs in `crawl_plans`, not PHPUnit.

## Rules

- Extend an existing test file when the subject fits; add a new file only when it does not.
- Read the SUT function or page first, and check which request keys it actually reads.
- Keep fixture dependencies explicit, and add the smallest deterministic fixture change.
- Restore any row a test mutates, and flush caches after writes (see `docs/lib-test-pitfalls.md`).
- HTTP tests must assert locale-independent output, because `config-overrides` renders in `fi_FI`.
- Use specific value assertions with descriptive test names; follow the assertion-quality rules in `AGENTS.md`.
- For a regression test, prove it fails against the pre-change SUT (for example, a git worktree passed with `--sut-path`).

## Validation

Run the narrowest command first (`./test:<suite> --test-filter <pattern>`), then run `./test:matrix` before pushing.
