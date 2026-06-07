# Plan: Deepen the 14 Sub-50% Lib Test Files

## For the implementing model (Sonnet)

This is a self-contained, repetitive execution plan. Follow it file-by-file. Do **not** redesign the harness, change the coverage pipeline, or touch the SUT. Your job is to raise per-file test depth on 14 lib files that have only shallow scaffold tests today.

## Background (grounded, 2026-05-29)

The lib-test campaign created a test file for all 42 `../ultiorganizer/lib/*.php` files, but only 22 reached the campaign's ≥80% per-file goal. **14 files are still under 50%** — typically ~2 test methods against many untested functions. This plan finishes them.

Per CLAUDE.md, **do not chase the global coverage percentage** — it is low by design. The goal is per-file depth on the file under test.

## Read these first (every session)

- `docs/ai/write-lib-file-test/SKILL.md` — the one-file-per-lib workflow
- `docs/ai/use-coverage-for-tests/SKILL.md` — how to use coverage to target branches
- `docs/lib-tests.md`, `docs/phpunit.md`, `docs/fixtures.md`
- `docs/lib-test-pitfalls.md` and `docs/lib-test-deep-coverage.md`
- `config/lib-test-catalog.json` — the catalog entry for the target file

## Known pitfalls (from prior sessions — apply proactively)

- **Cache staleness:** after a DB `UPDATE`/`INSERT`, call `CacheForgetNamespace(...)` before asserting on the affected read, or you assert on stale cached data.
- **`assertContains` is strict:** the DB returns strings, so `assertContains(300, $rows)` fails against `'300'`. Compare as strings.
- **Self-role session branch:** call role-management functions with `'admin'` to cover the `SetUserSessionData`-for-self branches.
- **Destructive functions** (`PrivacyAnonymizePlayer`, `PrivacyDeleteUserData`, `RemoveCountry`, delete/anonymize paths): each test mutates the fixture DB. The DB is recreated per *run*, not per *test*, so isolate — operate on rows you insert in the test, assert, and don't depend on ordering with other tests. Prefer creating a throwaway player/country in the test over mutating shared fixture rows.
- **Defer un-coverable branches:** lines reachable only via `die()`/`exit()`/redirect or non-`lib/` entrypoint bootstrap are out of scope. Record them as triage notes instead of forcing brittle coverage.

## Step 1 — Triage + reclassify (do this before writing tests)

1. For each of the 14 files, open the matching test and read the uncovered branches in `coverage/html/index.html` (parse `clover.xml`, match the `lib/<file>.php` suffix). Classify each file's gap as **shallow-gap** (testable, just no tests) or **hard-to-reach** (entrypoint/redirect/dead code). Write the classification into the catalog entry's `triage_notes` and set `triage_status`.
2. **Reclassify `privacy.functions.php`:** it is currently a `bootstrap_only` *unit* test (`tests/Unit/Lib/PrivacyFunctionsLibTest.php`) that only exercises pure string helpers, while its DB-backed report/anonymize logic (27 functions) is untested. Move it to the integration suite with a `database_backed` load profile, using the deterministic catalog path. Update the catalog entry to match. (`country` confirmed similar: 15 DB functions, 2 tests.)

## Step 2 — Deepen, in this order (highest uncovered-line leverage first)

| # | File | Current | Uncovered lines | Suite |
|---|------|---------|-----------------|-------|
| 1 | team.functions.php | 12.3% | 804 | integration |
| 2 | privacy.functions.php | 2.1% | 525 | integration (after reclassify) |
| 3 | country.functions.php | 7.2% | 492 | integration |
| 4 | standings.functions.php | 24.5% | 486 | integration |
| 5 | series.functions.php | 14.5% | 348 | integration |
| 6 | season.functions.php | 24.0% | 320 | integration |
| 7 | url.functions.php | 18.4% | 186 | check catalog |
| 8 | translation.functions.php | 11.7% | 182 | check catalog |
| 9 | logging.functions.php | 29.6% | 157 | integration |
| 10 | image.functions.php | 11.5% | 100 | check catalog |
| 11 | location.functions.php | 13.6% | 95 | integration |
| 12 | configuration.functions.php | 44.3% | 64 | integration |
| 13 | persistent-cache.functions.php | 48.5% | 52 | integration |
| 14 | debug.functions.php | 47.1% | 9 | unit |

Confirm the suite/profile from the catalog entry before writing; the table is a guide.

## Per-file loop (repeat for each file)

1. Read the catalog entry; reuse its deterministic test path. Do not invent a new filename or split a file across multiple test files.
2. Read the target `lib/<file>.php` to understand its functions and which are DB-backed vs pure.
3. Run the owning suite to get fresh coverage:
   - `./test:integration` (or `./test:unit` for `debug`)
   - or `./test:filter baseline-default <Pattern>` to iterate fast on one class.
   - Coverage is wiped each run — always rerun before reading it; never trust a stale `coverage/` dir.
4. Open `coverage/html/index.html` for the target file; list the meaningful uncovered branches.
5. Add focused, **real** assertions (not assertion-free line-coloring) for deterministic, fixture-backed paths first. If a path needs a fixture row that doesn't exist, create it inside the test rather than expanding `fixtures/baseline.sql` (only extend the fixture pack if multiple files need it — see `docs/fixtures.md`).
6. Rerun the suite; confirm the intended lines moved from uncovered to covered and the suite is green.
7. **Acceptance:** file reaches **≥80%**, OR the remaining gap is documented as hard-to-reach in the catalog `triage_notes` with the residual percent explained.
8. `./libtest:catalog-refresh` to update the catalog.
9. Commit, matching the existing message style:
   `Add integration tests for <file> (NN.N% coverage)`
   (Use `git` only — branch off `main` first if not already on a feature branch; do not push unless asked.)

## Guardrails

- Keep the SUT read-only. No edits under `../ultiorganizer`, `.runtime/`, or `reports/`.
- Do not widen the `<source>` scope in `phpunit.xml.dist`.
- Do not convert a test task into an SUT refactor — if a file genuinely can't be tested without one, record it per `docs/lib-test-deep-coverage.md` and move on.
- One lib file = one test file = one catalog entry. Don't broaden into a repo-wide regeneration.
- Stop when the file's meaningful behavior is asserted, not when a number is hit.

## Definition of done

All 14 files either ≥80% line coverage or carrying an explicit `triage_status` + `triage_notes` explaining the residual. Catalog refreshed. Each file committed separately. The full `./test:integration` (and `./test:unit`) suites stay green.

## Out of scope (Tier 2 — leave for a separate, judgment-heavy effort)

- CI coverage-regression guard
- Mutation testing (Infection) to validate assertion quality on the already-≥80% files
- Expanding customization/config-overrides cases beyond smoke/crawl
