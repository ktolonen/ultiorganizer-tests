# Ultiorganizer Test Harness

This repository contains a Dockerized test harness for the production Ultiorganizer codebase.

By default, the system under test is read from `/home/kari/code/ultiorganizer`.

The harness does not modify that source tree directly. For each run it copies the SUT into `.runtime/`, injects test-only config there, recreates a disposable MariaDB database, and writes results to `reports/`.

## Requirements

- Docker
- Docker Compose
- Access to the Docker daemon
- The Ultiorganizer source checkout at `/home/kari/code/ultiorganizer`, or another checkout/worktree you pass with `--sut-path`

## Quick start

Check the environment first:

```sh
./doctor
```

Run the default day-to-day test command:

```sh
./test:quick
```

Run the full default case:

```sh
./test:case baseline-default
```

Read the latest summary:

```sh
./report:latest
```

## Common commands

Run just the unit suite:

```sh
./test:unit
```

Run just the integration suite:

```sh
./test:integration
```

Run just the smoke suite:

```sh
./test:smoke
```

Run one full case:

```sh
./test:case baseline-default
```

Run all cases in the matrix:

```sh
./test:matrix
```

Run integration tests with a PHPUnit filter:

```sh
./test:filter baseline-default common
```

Show the latest summary for any run:

```sh
./report:latest
```

Show the latest summary for one case:

```sh
./report:case baseline-default
```

Show log paths for one case:

```sh
./logs:case baseline-default
```

## Current default case

The current matrix contains one case:

- `baseline-default`
  - customization: `default`
  - config profile: `baseline`
  - fixture pack: `baseline`
  - suites: `unit`, `integration`, `smoke`

The baseline fixture is no longer only a minimal boot fixture. It now includes one current season, one valid series, one visible pool, two teams, reservations and location rows, two pool games, and minimal player/goal data so public standings and scoreboard pages render cleanly.

## Smoke coverage

The current smoke allowlist covers these public pages:

- `view=frontpage`
- `view=seasonlist`
- `view=allcountries`
- `view=teams&season=HRN2026&list=bystandings`
- `view=games&season=HRN2026&filter=tournaments&group=all`
- `view=seriesstatus&series=100`
- `view=poolstatus&pool=200`

Smoke failures are reported with the failing page id, HTTP status, response snippet, and Apache log excerpt when available.

## Using another SUT checkout

The default SUT path is `/home/kari/code/ultiorganizer`, but the Python harness also accepts an alternate checkout or worktree.

Example:

```sh
python3 scripts/harness.py case --case-id baseline-default --sut-path /path/to/other/ultiorganizer
```

You can use the same `--sut-path` pattern with `doctor`, `quick`, `suite`, `case`, and `matrix`.

## What each run does

For the selected case, the harness will:

1. Run SUT preflight checks.
2. Start `mariadb` and `php-test` with Docker Compose.
3. Ensure PHP test dependencies are installed with Composer.
4. Copy the SUT from the read-only mount into `.runtime/cases/<case-id>/sut`.
5. Generate a test-only `conf/config.inc.php` inside that runtime copy.
6. Drop and recreate the disposable test database.
7. Load the production schema from the SUT SQL dump.
8. Load the harness fixture pack.
9. Run PHPUnit for the requested suites.
10. Write setup logs, JUnit, raw logs, and summaries under `reports/`.

## Reports and artifacts

Latest summary:

- `reports/summary/latest.json`

Latest failed summary:

- `reports/summary/latest-failed.json`

Latest summary for the default case:

- `reports/cases/baseline-default/latest.json`

Latest failed summary for the default case:

- `reports/cases/baseline-default/latest-failed.json`

Per-run artifacts:

- `reports/cases/<case-id>/<run-id>/junit/`
- `reports/cases/<case-id>/<run-id>/logs/`
- `reports/cases/<case-id>/<run-id>/summary/`

Each summary includes:

- setup result
- failure classification
- failure reason
- first failed test when PHPUnit fails
- failed page details when smoke fails
- artifact paths for summary, JUnit, and raw logs

## Failure classes

The harness currently classifies failures as:

- `preflight_failure`
- `container_startup_failure`
- `runtime_sut_copy_config_failure`
- `database_initialization_failure`
- `fixture_load_failure`
- `phpunit_test_failure`
- `smoke_http_runtime_failure`

## MCP wrapper

The repository also contains a thin MCP wrapper in `mcp/server.py`.

It wraps the normal harness scripts instead of bypassing them. Current tools:

- `matrix_list`
- `matrix_run`
- `suite_run`
- `test_run`
- `report_latest`
- `report_case`
- `logs_case`

The MCP responses use the same summary payloads as the CLI entrypoints, including failure classification, failed smoke pages, first failed tests, and artifact paths.

## Notes

- The SUT is mounted read-only into the test container.
- The runtime copy is disposable and lives under `.runtime/`.
- Each run recreates the test database.
- `ALLOW_INSTALL=true` is set in generated test config so Ultiorganizer can boot through `index.php` while `install.php` still exists in the SUT tree.
- The harness will bring containers up automatically when needed.
