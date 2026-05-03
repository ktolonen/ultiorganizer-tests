# Ultiorganizer Test Harness

This repository contains a Dockerized test harness for the production Ultiorganizer codebase.

By default, the harness uses the sibling checkout at `../ultiorganizer`.

The harness does not modify that source tree directly. For each run it copies the SUT into `.runtime/`, injects test-only config there, recreates a disposable MariaDB database, and writes results to `reports/`.

## Requirements

- Docker
- Docker Compose
- Access to the Docker daemon
- The Ultiorganizer source checkout at `../ultiorganizer`, or another checkout/worktree you pass with `--sut-path`

## Quick start

Check the environment first:

```sh
./doctor
```

Run the default day-to-day test command:

```sh
./test:quick
```

Run only the SUT PHP syntax lint:

```sh
./test:lint
```

Run only export endpoint contract tests:

```sh
./test:export
```

Run only REST API contract tests:

```sh
./test:api
```

Run the full default case:

```sh
./test:case baseline-default
```

Read the latest summary:

```sh
./report:latest
```

Build the browser report index:

```sh
./report:html
```

Prune older report runs while keeping the newest 20:

```sh
./report:clean --keep 20
```

## Docs

- [Documentation Index](docs/README.md)
- [Architecture](docs/architecture.md)
- [PHP Syntax Lint](docs/lint.md)
- [Export Contract Testing](docs/export.md)
- [REST API Contract Testing](docs/api.md)
- [PHPUnit Suites](docs/phpunit.md)
- [Per-File Lib Tests](docs/lib-tests.md)
- [Smoke Testing](docs/smoke.md)
- [Crawl Testing](docs/crawl.md)
- [Matrix](docs/matrix.md)
- [Fixtures](docs/fixtures.md)
- [Reporting](docs/reporting.md)
- [MCP](docs/mcp.md)
- [Runtime](docs/runtime.md)
- [Local Workflow](docs/local-workflow.md)

## Current Lib Coverage

The per-file lib rollout is now in the incremental expansion phase. The harness currently has matching per-file tests for 15 top-level `lib/*.php` files, including pure helpers, guard files, shallow DB-backed reads, and one narrow event-log write path.

This work intentionally favors shallow but trustworthy coverage. Files that still depend on broad bootstrap state, redirects, `die()`-driven control flow, or mutation-heavy legacy paths are still deferred until they can be covered without brittle assumptions.

Run artifacts now also include a captured Apache/PHP error-log delta for each case run under the normal `reports/.../logs/` tree, and `./logs:case` exposes that path alongside suite logs.

## Common commands

Run just the unit suite:

```sh
./test:unit
```

Run just the PHP syntax lint suite:

```sh
./test:lint
```

Run just the integration suite:

```sh
./test:integration
```

Run just the export contract suite:

```sh
./test:export
```

Run just the REST API contract suite:

```sh
./test:api
```

Run just the smoke suite:

```sh
./test:smoke
```

Run just the crawl suite:

```sh
./test:crawl
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

Build `reports/index.html` with an index of all recorded runs and a detail view:

```sh
./report:html
```

Preview report cleanup without deleting anything:

```sh
./report:clean --keep 20 --dry-run
```

Delete older report run directories and rebuild `reports/index.html`:

```sh
./report:clean --keep 20
```

Refresh the per-file lib catalog:

```sh
./libtest:catalog-refresh
```

Report missing matching per-file lib tests:

```sh
./libtest:missing
```

Run one matching per-file lib test:

```sh
./libtest:run --lib-file common.functions.php
```

Scaffold one matching per-file lib test:

```sh
./libtest:scaffold --lib-file common.functions.php
```

## Current default case

The current matrix contains one case:

- `baseline-default`
  - customization: `default`
  - config profile: `baseline`
  - fixture pack: `baseline`
  - suites: `unit`, `integration`, `smoke`, `crawl`

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

## Crawl coverage

The harness also supports a case-scoped `crawl` suite for broader route discovery and artifact capture.

The default case currently includes one crawl plan:

- `public-follow-links`
  - type: `follow_links`
  - start path: `?view=frontpage`
  - purpose: recursively follow in-scope public links from the harness-managed runtime copy
- `public-ext-php`
  - type: `php_files`
  - input root: `ext`
  - base URL: `http://127.0.0.1/ext`
  - purpose: fetch directly addressable public extension endpoints as files
- `superadmin-follow-links`
  - type: `follow_links`
  - start path: `?view=admin/serverconf`
  - auth: `admin` / `harness-admin`
  - purpose: crawl authenticated admin-visible pages while excluding obviously destructive database routes
- `anonymous-sensitive-paths`
  - type: `path_probes`
  - purpose: probe sensitive direct-file and traversal-style URLs anonymously and assert they stay blocked
  - current expectations: admin entrypoint redirects away, direct config/lib access is forbidden, traversal-style URLs return blocked/error responses

Unlike `smoke`, which is a small deterministic allowlist, `crawl` is intended for broader probing. Crawl failures are reported at the plan level and include the plan artifact directory and raw crawler log paths.

## Using another SUT checkout

The default SUT path is `../ultiorganizer`. The Python harness also accepts any alternate checkout or worktree.

Example:

```sh
python3 scripts/harness.py case --case-id baseline-default --sut-path /path/to/other/ultiorganizer
```

You can use the same `--sut-path` pattern with `doctor`, `quick`, `suite`, `case`, and `matrix`.

## Local development vs PR validation

The harness now records SUT git context with each run and can keep separate "latest" pointers per context label. That gives you a practical manual workflow without CI:

- Local development: point at your normal checkout or worktree and let the harness infer a branch-scoped context label.
- PR validation: point at a PR checkout or worktree and pass `--pr-number` so the reports are tracked under `pr-<number>`.

Example local branch run:

```sh
./test:quick --sut-path ../ultiorganizer
./report:latest --context-label branch-my-feature
```

Example manual PR run from a local PR checkout:

```sh
./test:case baseline-default \
  --sut-path ../ultiorganizer-pr-123 \
  --pr-number 123 \
  --pr-head-ref feature/my-change \
  --pr-base-ref main

./report:case baseline-default --context-label pr-123
./logs:case baseline-default --context-label pr-123
```

If you want a stable label that does not depend on the git branch name, pass `--context-label` explicitly.

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
9. Run the requested suites, including PHPUnit suites and any configured crawl plans.
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
- SUT context metadata such as branch, commit, dirty state, and optional PR number
- artifact paths for summary, JUnit, and raw logs

Context-scoped latest pointers are also written when a context label is available:

- `reports/summary/contexts/<context-label>/latest.json`
- `reports/summary/contexts/<context-label>/latest-failed.json`
- `reports/cases/<case-id>/contexts/<context-label>/latest.json`
- `reports/cases/<case-id>/contexts/<context-label>/latest-failed.json`

## Failure classes

The harness currently classifies failures as:

- `preflight_failure`
- `container_startup_failure`
- `runtime_sut_copy_config_failure`
- `database_initialization_failure`
- `fixture_load_failure`
- `phpunit_test_failure`
- `smoke_http_runtime_failure`
- `crawl_runtime_failure`

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
