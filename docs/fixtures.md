# Fixtures

## Purpose

Fixture packs provide deterministic test data for the disposable MariaDB database.

They are loaded after the production schema from the SUT. This lets the harness exercise real application code against a known dataset without modifying the production checkout.

Fixture SQL files live under `fixtures/`.

## Loading Order

For each case run, the harness:

1. recreates the disposable database
2. loads the production schema from the SUT SQL dump
3. loads the selected fixture pack from this repository

This means fixture packs should assume the schema already exists.

## Selection

Each matrix case chooses its fixture pack with `fixture_pack` in `config/matrix.json`.

The harness then loads:

- `fixtures/<fixture_pack>.sql`

## Current Baseline Fixture

The main fixture pack is `fixtures/baseline.sql`.

It currently includes:

- one current season
- one valid series
- one visible pool
- two teams
- reservations and one location
- two pool games
- minimal player and goal data
- one deterministic superadmin account for authenticated crawl coverage

The goal is not to mirror a full production database. The goal is to create enough stable data for meaningful runtime and integration coverage.

## Design Rules

- Keep fixtures deterministic.
- Prefer the smallest dataset that still supports the intended tests.
- Put test-only accounts and test-only data here, not in the production repo.
- Add a new fixture pack when the data shape needs to differ materially between cases.

## When To Add Another Fixture Pack

Add another pack when tests need:

- a different season or competition shape
- different permission data
- different feature flags or content visibility behavior
- a dataset too specialized to keep in the baseline pack
