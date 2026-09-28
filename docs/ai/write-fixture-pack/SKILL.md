---
name: write-fixture-pack
description: Add or update deterministic SQL fixture data for the harness. Use when tests need stable database-backed state that the current fixture pack does not provide. Prefer the smallest possible change to an existing fixture pack and add a new pack only when the data shape meaningfully differs between cases.
metadata:
  short-description: Write or update SQL fixture packs
---

# Write Fixture Pack

Add or update deterministic SQL fixture data. Read `docs/fixtures.md` first.

## Rules

- Extend `fixtures/baseline.sql` by default. Add a new pack only when a case needs a materially different data shape, such as a different permission model, event structure, or feature state.
- Check whether the baseline already has what you need in another form.
- Add the smallest row set, with explicit stable ids and readable relationships.
- The SUT schema is loaded first, so fixture SQL assumes the tables exist.
- Baseline changes affect every case and many tests, so check neighboring assertions that pin counts or ids.
- If a test can seed and restore its own rows, prefer that over a global fixture change.

## Validation

Run the narrowest affected suite first (`./test:integration`, `./test:smoke`, `./test:crawl`), then `./test:matrix`.
