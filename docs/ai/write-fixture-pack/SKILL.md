---
name: write-fixture-pack
description: Add or update deterministic SQL fixture data for the harness. Use when tests need stable database-backed state that the current fixture pack does not provide. Prefer the smallest possible change to an existing fixture pack and add a new pack only when the data shape meaningfully differs between cases.
metadata:
  short-description: Write or update SQL fixture packs
---

# Write Fixture Pack

Write or update SQL fixture packs for this harness.

Always read these references first:

- `docs/fixtures.md`
- `docs/matrix.md`
- `docs/phpunit.md`
- `docs/crawl.md`

## Purpose

Use this skill when a test needs deterministic DB-backed data that does not already exist in the selected fixture pack.

Typical uses:

- integration test coverage for a new helper behavior
- smoke coverage that needs additional public data
- crawl coverage that needs authenticated users or visible content

## Default Strategy

Prefer the smallest change to the existing fixture pack first.

In this repo, that usually means updating:

- `fixtures/baseline.sql`

Add a new fixture pack only when the data shape should differ materially between cases.

## Data Design Rules

- Keep data deterministic.
- Add only the rows required for the intended behavior.
- Prefer explicit IDs and stable values over generated or implicit ones.
- Keep fixture relationships readable.
- Avoid production-like bulk data when a small targeted shape is enough.

## Environment Rules

- The production schema is loaded first from the SUT.
- Fixture SQL is applied after the schema.
- Fixture files should assume the schema already exists.

## When To Add A New Fixture Pack

Add a new pack when the tests need a meaningfully different environment, for example:

- different permission model
- different season or event structure
- a feature state that would make the baseline pack confusing or overloaded

If the baseline case still represents the same environment and just needs one more row or one more related cluster of rows, update the existing pack instead.

## Test Alignment Rules

- Match fixture changes to the tests that need them.
- Keep the dependency visible in test naming or test assertions.
- If a fixture change exists only for authenticated crawl support, document that in nearby docs or comments when useful.
- Prefer helper-facing or page-facing meaningful data over arbitrary placeholder rows.

## Workflow

1. Identify which test or crawl plan actually needs new data.
2. Check whether `baseline.sql` already has the needed entities in another form.
3. Add the smallest deterministic row set that enables the behavior.
4. Keep IDs, names, and relationships stable and understandable.
5. Run the smallest relevant harness command first.
6. Run the broader case only if the fixture change could affect neighboring coverage.

## Validation Commands

Use the smallest relevant command first:

- `./test:integration`
- `./test:smoke`
- `./test:crawl`
- `./test:filter baseline-default <pattern>`

Use broader validation when needed:

- `./test:quick`
- `./test:case baseline-default`

## Boundaries

- Do not put test-only config in the production checkout.
- Do not create manual runtime data under `.runtime/`.
- Do not add large fixture expansions when one or two rows would cover the behavior.
- Do not create a new fixture pack when a small deterministic update to the current one is enough.
