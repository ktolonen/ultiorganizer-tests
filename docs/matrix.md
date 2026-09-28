# Matrix

`config/matrix.json` declares the cases the harness can run. A case fixes the environment: `customization`, `config_profile`, `fixture_pack`, `database_name`, and `suites`, plus optional `smoke_pages`, `crawl_plans`, and `tags`.

## Current Cases

| Case | Profile | Suites | Purpose |
|---|---|---|---|
| `baseline-default` | `baseline` | all seven | Default developer path and reference shape |
| `customization-{bula,fpudd,gummis,slkl,wfdf,windmill}` | `baseline` | `smoke`, `crawl` (`public-follow-links` only) | Each `cust/*` `CUSTOMIZATIONS` value boots its header, stylesheet, and schedule includes |
| `config-overrides` | `config-overrides` | `integration`, `smoke` | Non-default config constants and server settings (renders in `fi_FI`) |

All cases use the `baseline` fixture pack with a unique database name.

Tests that pass on `baseline-default` can still fail on `config-overrides` (locale, overridden settings), so run `./test:matrix`, not just one case, before pushing.

## When To Add A Case

Add a case only when the environment changes: customization, config profile, fixture pack, or a meaningfully different suite set. For more route coverage, add `smoke_pages` or `crawl_plans` to an existing case instead.

## Commands

- `./test:case <case-id> [--suites a,b]`: one case, optionally a subset of suites
- `./test:matrix`: every case, each with a fresh runtime copy, database, and report directory
