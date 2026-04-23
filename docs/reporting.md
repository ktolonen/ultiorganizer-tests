# Reporting

## Purpose

The harness writes structured run artifacts so failures can be inspected after the run instead of only through terminal output.

Reports live under `reports/`.

## Main Locations

- `reports/summary/latest.json`: latest summary across runs
- `reports/summary/latest-failed.json`: latest failed summary across runs
- `reports/cases/<case-id>/latest.json`: latest summary for one case
- `reports/cases/<case-id>/latest-failed.json`: latest failed summary for one case

Per-run artifacts live under:

- `reports/cases/<case-id>/<run-id>/`

## Per-Run Artifacts

A run may contain:

- `logs/`: setup log and per-suite raw logs
- `junit/`: JUnit XML for PHPUnit suites
- `crawl/`: per-plan crawl artifacts
- `summary/summary.json`: canonical machine-readable summary
- `summary/summary.md`: human-readable summary

## Summary Contents

The JSON summary includes:

- overall status
- case id
- suites requested and run
- setup result
- per-suite results
- failure classification
- failure reason
- first failed test when relevant
- failed smoke page details when relevant
- SUT git context and optional PR metadata
- artifact paths

## Context Labels

When a context label is available, the harness also writes latest pointers under:

- `reports/summary/contexts/<context-label>/`
- `reports/cases/<case-id>/contexts/<context-label>/`

This is useful for keeping separate latest pointers for local branches and PR worktrees.

## Failure Classes

Current failure classes include:

- `preflight_failure`
- `container_startup_failure`
- `runtime_sut_copy_config_failure`
- `database_initialization_failure`
- `fixture_load_failure`
- `phpunit_test_failure`
- `smoke_http_runtime_failure`
- `crawl_runtime_failure`

## Reading Results

Common commands:

- `./report:latest`
- `./report:case <case-id>`
- `./logs:case <case-id>`

Use the JSON summaries for automation and the Markdown summary plus raw logs for manual debugging.
