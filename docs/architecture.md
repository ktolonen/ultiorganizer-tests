# Architecture

This repository is a Dockerized test harness for the Ultiorganizer codebase (default: `../ultiorganizer`). It owns orchestration, the disposable runtime copy and database, tests, and reports. It never owns or modifies production code, config, or state.

## Components

- `scripts/harness.py`: host-side CLI (`doctor`, `quick`, `suite`, `case`, `matrix`, reports, `lib-test-*`). All `./test:*`, `./report:*`, `./libtest:*` wrappers call it.
- `scripts/container_runner.py`: in-container runtime copy, config generation, DB bootstrap, suite execution, coverage merge, summaries.
- `scripts/libtest_coverage.py`: per-lib-file coverage report behind `./libtest:coverage`.
- `scripts/crawl/*.sh`: wget helpers used by crawl plans.
- `config/matrix.json`, `config/profiles/*.json`, `config/lib-test-catalog.json`: cases, config profiles, lib-test catalog.
- `fixtures/*.sql`: fixture packs loaded after the SUT schema.
- `tests/{Unit,Integration,Export,Api,Smoke}`: PHPUnit suites; `tests/Support/LegacyApp.php` loads SUT lib files.
- `tests/Js`: host Node tests (not part of the Docker flow).
- `docker-compose.yml`, `docker/php-test/`: `php-test` (PHP 8.3 + Apache + PCOV + Node) and `mariadb`.
- `mcp/server.py`: thin MCP wrapper over `harness.py`.

## Run Flow

1. Preflight the SUT path; start `mariadb` and `php-test`; ensure Composer deps.
2. Copy the read-only SUT mount into `.runtime/cases/<case-id>/sut` and generate `conf/config.inc.php` there.
3. Recreate the case database, load the SUT schema, then the fixture pack.
4. Run the requested suites; merge coverage if any in-process suite ran.
5. Capture the Apache/PHP error-log delta and write summaries and latest pointers under `reports/`.

A setup failure skips all suites.

## Suites

| Suite | What it checks |
|---|---|
| `lint` | `php -l` over every SUT PHP file; cheapest gate |
| `unit` | In-process PHP, no DB dependence |
| `integration` | In-process PHP against the fixture DB |
| `export` | HTTP contracts for `ext/` endpoints |
| `api` | HTTP contracts for `/api/v1` |
| `smoke` | `smoke_pages` allowlist plus page-level HTTP tests |
| `crawl` | Declarative `crawl_plans` (link following, direct PHP fetches, path probes) |

## Design Rule

Test-only config and data belong in the runtime copy and disposable database, never in the SUT checkout. Orchestration belongs in the Python scripts; MCP and shell wrappers stay thin.
