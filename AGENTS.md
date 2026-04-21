# AGENTS.md

Implementation summary for the current Ultiorganizer test harness.

## Repo purpose

- This repository is a separate Dockerized test harness for the production Ultiorganizer codebase, typically at sibling path `../ultiorganizer`.
- The SUT is mounted read-only into the test container and copied into `.runtime/cases/<case-id>/sut` before test config is injected.
- Every run recreates the disposable MariaDB test database and writes reports under `reports/`.

## Current implementation

- `docker-compose.yml`: starts `php-test` and `mariadb`.
- `docker/php-test/Dockerfile`: PHP 8.3 Apache image with mysqli, gettext, mbstring, Composer, Python, MariaDB client, locales, and Apache access config for the runtime webroot.
- `config/matrix.json`: currently defines one default case, `baseline-default`, with public-page smoke coverage for frontpage, season list, countries, standings, schedule, series status, and pool status.
- `config/profiles/baseline.json`: baseline test config profile.
- `fixtures/baseline.sql`: deterministic public standings fixture pack with one season, one series, one visible pool, two teams, reservations/location rows, two pool games, and minimal player/goal data for scoreboard pages.
- `scripts/harness.py`: host-side orchestration for preflight, `doctor`, `quick`, suites, cases, matrix runs, and report access.
- `scripts/container_runner.py`: container-side orchestration for runtime SUT copy, config generation, DB bootstrap, fixture loading, PHPUnit suite execution, and report writing.
- `tests/Unit`, `tests/Integration`, `tests/Smoke`: PHPUnit suites for helper functions, DB-backed season/team/pool/config reads, and HTTP page smoke checks.
- `mcp/server.py`: thin stdio JSON-RPC MCP wrapper over the normal script entrypoints.

## Stable entrypoints

- `./doctor`
- `./test:quick`
- `./test:unit`
- `./test:integration`
- `./test:smoke`
- `./test:case baseline-default`
- `./test:matrix`
- `./test:filter baseline-default <pattern>`
- `./report:latest`
- `./report:case baseline-default`
- `./logs:case baseline-default`

## Runtime behavior

- The harness uses the sibling SUT path `../ultiorganizer` by default and also accepts `--sut-path` for worktrees or alternate checkouts.
- The generated test config sets `ALLOW_INSTALL=true` because Ultiorganizer refuses to boot through `index.php` while `install.php` exists otherwise.
- DB bootstrap uses MariaDB over plain TCP with SSL disabled for the local container network.
- Apache serves `/workspace/.runtime/webroot`, which is a symlink to the prepared runtime SUT copy for the active case.
- `./doctor` checks SUT preflight, Docker access, Compose services, stack startup, and MariaDB connectivity from `php-test`.
- `./test:quick` runs `unit` plus `integration` for `baseline-default` and is the default day-to-day command.

## Reporting

- Canonical run artifacts live under `reports/cases/<case-id>/<run-id>/`.
- `reports/summary/latest.json` stores the latest run summary.
- `reports/cases/<case-id>/latest.json` stores the latest summary for a specific case.
- Failed runs also refresh `reports/summary/latest-failed.json` and `reports/cases/<case-id>/latest-failed.json`.
- Each run writes a setup log plus per-suite JUnit XML and raw suite logs.
- Summaries include failure classification, failure reason, first failed test, failed smoke pages, and direct artifact paths.
- Summaries also include SUT context metadata such as branch, commit, dirty state, inferred or explicit context label, and optional PR number metadata.
- Context-scoped latest pointers are written under `reports/summary/contexts/<context-label>/` and `reports/cases/<case-id>/contexts/<context-label>/`.
- Current failure classes used by the harness are:
  - `preflight_failure`
  - `container_startup_failure`
  - `runtime_sut_copy_config_failure`
  - `database_initialization_failure`
  - `fixture_load_failure`
  - `phpunit_test_failure`
  - `smoke_http_runtime_failure`

## MCP notes

- The MCP wrapper exposes `matrix_list`, `matrix_run`, `suite_run`, `test_run`, `report_latest`, `report_case`, and `logs_case`.
- MCP must stay a thin wrapper over the existing harness scripts; do not duplicate orchestration logic in `mcp/server.py`.
- MCP tool responses inherit the same structured payloads as the CLI paths, including failure classification and artifact paths.

## Working rules for future changes

- Keep the SUT read-only from the harness perspective. Test-only config belongs in the runtime copy, not in the production repo.
- Add new cases in `config/matrix.json` and new profile data in `config/profiles/` instead of hardcoding more branches into the scripts.
- Prefer extending the existing Python orchestration instead of replacing it with ad hoc shell logic.
- Keep generated artifacts out of git; do not hand-edit `.runtime/` or `reports/`.
