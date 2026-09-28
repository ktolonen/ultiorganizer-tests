# Fixtures

A fixture pack is deterministic SQL loaded after the SUT's production schema, into a freshly recreated database. Each case picks one with `fixture_pack`, which loads `fixtures/<fixture_pack>.sql`. Packs assume the schema already exists.

## Baseline Pack

`fixtures/baseline.sql` is the only pack. It contains:

- server settings (for example `CurrentSeason=HRN2026` and locale `en_GB`)
- season `HRN2026`, which is public and API-public
- series 100 and visible pool 200
- teams 300 and 301
- location 400 and reservations 500 and 501
- games 700 (played, with players, goals, and events) and 701 (unplayed)
- user `admin` / `harness-admin` with the superadmin role
- API token `harness-api-token`, scoped to `HRN2026`

## Rules

- Keep data deterministic, with explicit ids.
- Add the smallest row set a test needs.
- Keep test-only accounts and data here, never in the SUT.
- Tests that mutate fixture rows must restore them.
- Add a new pack only when the data shape must differ materially: another competition shape, permission model, or visibility state.
