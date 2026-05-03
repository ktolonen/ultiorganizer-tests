# Matrix

## Purpose

The matrix defines which test environments the harness knows how to run.

Each matrix entry is called a case. A case describes:

- which SUT customization is active
- which test-only config profile is injected
- which fixture pack is loaded
- which suites are enabled
- which smoke pages and crawl plans belong to that environment

The matrix lives in `config/matrix.json`.

## Case Shape

Each case typically defines:

- `id`
- `description`
- `customization`
- `config_profile`
- `fixture_pack`
- `database_name`
- `suites`

The default `baseline-default` case currently runs `lint`, `unit`, `integration`, `export`, `api`, `smoke`, and `crawl`.

Optional runtime coverage data:

- `smoke_pages`
- `crawl_plans`
- `tags`

## Design Rule

Use a new case when the environment changes.

Examples:

- different customization
- different config profile
- different fixture pack
- different enabled suite set because the environment meaningfully differs

Do not create a new case just to add more route coverage. In that situation:

- add `smoke_pages` for deterministic public checks
- add `crawl_plans` for broader runtime or security coverage

## Execution

- `./test:case <case-id>` runs one case
- `./test:matrix` runs every declared case

Each case run gets:

- a fresh runtime SUT copy
- a fresh disposable database
- its own reports directory

## Current State

The repository currently has one main case:

- `baseline-default`

That case is the default developer validation path and the current reference shape for adding more cases later.
