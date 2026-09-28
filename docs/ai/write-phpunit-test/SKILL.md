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

## Prove The Test Discriminates

A passing test is not evidence that its assertion can fail. For a regression test or a strengthened assertion:

1. **Pre-change run.** Check out the SUT at the commit before the change and run the test against it; it must fail.

   ```sh
   git -C ../ultiorganizer worktree add --detach <scratch>/sut-pre <commit-before-change>
   ./test:<suite> --test-filter <pattern> --sut-path <scratch>/sut-pre
   ```

   One worktree before the earliest change covers several changes. This catches fixtures where old and new behavior coincide, for example an `ORDER BY` test whose rows are already in primary-key order.
2. **Mutation run.** A pre-change failure proves little when the function did not exist before (`Call to undefined function` fails any assertion). In a worktree at the current commit, inject one realistic bug into the SUT function (drop a factor, make a condition unconditional, remove one `OR` branch, shift an offset) and confirm the test fails. Revert and try the next mutation. If the test still passes, the assertion is not doing its job. Typical culprits:
   - A tautology: `mm * 60 + ss === elapsed` holds for any `elapsed` when the SUT derives `mm` and `ss` from it.
   - A negative-only assertion (`[]`, `false`, `null`), which an over-strict gate satisfies. Add the row or flag that flips the result and assert it flips.
   - Cases that all sit on one side of a branch. Add a case whose expected value differs from every other case.
3. Confirm an unmodified copy passes, use a fresh `--sut-path` directory per round (see `docs/local-workflow.md`, Troubleshooting), and remove worktrees with `git worktree remove --force` when done.

## Validation

Run the narrowest command first (`./test:<suite> --test-filter <pattern>`), then run `./test:matrix` before pushing.
