# Reporting

## Purpose

The harness writes structured run artifacts so failures can be inspected after the run instead of only through terminal output.

Reports live under `reports/`.

## Main Locations

- `reports/summary/latest.json`: latest summary across runs
- `reports/summary/latest-failed.json`: latest failed summary across runs
- `reports/cases/<case-id>/latest.json`: latest summary for one case
- `reports/cases/<case-id>/latest-failed.json`: latest failed summary for one case
- `reports/index.html`: generated browser view over all recorded runs

Per-run artifacts live under:

- `reports/cases/<case-id>/<run-id>/`

## Per-Run Artifacts

A run may contain:

- `logs/`: setup log and per-suite raw logs
- `logs/apache-error.log`: new Apache/PHP error-log content captured during the run
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
- run-level Apache/PHP error-log metadata and excerpt
- SUT git context and optional PR metadata
- artifact paths

The Apache/PHP error-log data is grouped under `runtime_logs.apache_error_log` and includes:

- source path inside the container
- captured artifact path under `reports/.../logs/apache-error.log`
- whether new log content was detected during the run
- whether the captured delta matches common PHP issue patterns
- a short excerpt for report rendering

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
- `php_lint_failure`
- `phpunit_test_failure`
- `smoke_http_runtime_failure`
- `crawl_runtime_failure`

## Reading Results

Common commands:

- `./report:latest`
- `./report:case <case-id>`
- `./report:html`
- `./report:clean --keep 20`
- `./logs:case <case-id>`

`./report:html` scans `reports/cases/*/*/summary/summary.json` and writes a self-contained `reports/index.html`.
Open that file in a browser to filter all runs in newest-first time order, select a run, inspect suite results, and jump to linked logs, JUnit files, Markdown summaries, or raw JSON.
Crawl suite details include one row per crawl plan and direct links to each plan log.

`./report:clean --keep <count>` deletes older per-run directories under `reports/cases/<case-id>/<run-id>/`, prunes stale latest-pointer JSON files when they pointed at deleted runs, and refreshes `reports/index.html`.
Use `--dry-run` to preview deletions, `--case-id <case-id>` to limit cleanup to one case, or `--all` to delete all per-run directories.

Use the JSON summaries for automation and the Markdown summary plus raw logs for manual debugging. When smoke or crawl failures are unclear, check the captured `apache_error_log` path from `./logs:case` or from `artifact_paths.apache_error_log` in the summary.
