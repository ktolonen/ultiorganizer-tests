# Architecture

## Purpose

This repository is a separate Dockerized test harness for the production Ultiorganizer codebase, usually located at `../ultiorganizer`.

The harness owns:

- test orchestration
- disposable runtime preparation
- disposable MariaDB test data
- test execution
- reports and logs

The harness does not own:

- production application code
- production configuration
- persistent application state

## Main Components

- `scripts/harness.py`: host-side entrypoint for `doctor`, suite runs, case runs, matrix runs, and report access
- `scripts/container_runner.py`: container-side runtime preparation, database bootstrap, suite execution, crawl execution, and summary writing
- `config/matrix.json`: declarative case definitions, enabled suites, smoke pages, and crawl plans
- `config/profiles/*.json`: test-only config profiles injected into the runtime copy
- `fixtures/*.sql`: deterministic fixture packs loaded after the production schema
- `tests/Unit`, `tests/Integration`, `tests/Export`, `tests/Api`, `tests/Smoke`: PHPUnit suites
- `docker-compose.yml` and `docker/php-test/Dockerfile`: runtime services and test image
- `mcp/server.py`: thin MCP wrapper over the normal harness commands

## Execution Model

Each run follows the same high-level flow:

1. Validate the SUT path and required files.
2. Start `mariadb` and `php-test`.
3. Copy the SUT from the read-only mount into `.runtime/cases/<case-id>/sut`.
4. Generate test-only `conf/config.inc.php` inside that runtime copy.
5. Recreate the disposable test database.
6. Load the production schema from the SUT.
7. Load the selected fixture pack from this repository.
8. Run the requested suites.
9. Write summaries, logs, and latest pointers under `reports/`.

## Runtime Boundaries

- SUT source: mounted read-only into the test container
- Runtime SUT: copied into `.runtime/cases/<case-id>/sut`
- Webroot: `.runtime/webroot` symlink to the active runtime SUT copy
- Database: disposable MariaDB schema per case run
- Reports: persisted under `reports/`

This separation is the core design rule: test-only config and data belong in the runtime copy and disposable database, not in the production checkout.

## Suite Types

- `lint`: SUT-wide PHP syntax checks using `php -l`
- `unit`: PHPUnit tests that do not require DB-backed application state
- `integration`: PHPUnit tests that exercise DB-backed application behavior
- `export`: PHPUnit HTTP contract tests for public export endpoints
- `api`: PHPUnit HTTP contract tests for the versioned JSON API
- `smoke`: deterministic public page checks driven by `smoke_pages`
- `crawl`: broader route and path probing driven by `crawl_plans`

`lint` is the cheapest first gate. `smoke` is intentionally small and stable. `crawl` is broader and artifact-heavy.

Related documents:

- [PHP Syntax Lint](lint.md)
- [Export Contract Testing](export.md)
- [REST API Contract Testing](api.md)
- [PHPUnit Suites](phpunit.md)
- [Smoke Testing](smoke.md)
- [Crawl Testing](crawl.md)
- [Matrix](matrix.md)
- [Fixtures](fixtures.md)
- [Reporting](reporting.md)
- [MCP](mcp.md)
- [Runtime](runtime.md)
- [Local Workflow](local-workflow.md)

## Control Surfaces

There are three user-facing control layers:

- shell wrappers such as `./test:quick` and `./test:case`
- `scripts/harness.py` as the canonical CLI
- `mcp/server.py` as a thin JSON-RPC wrapper

The MCP layer should stay thin. Orchestration logic belongs in the existing harness scripts, not duplicated in the MCP server.

## Reporting Model

Canonical artifacts are written under `reports/cases/<case-id>/<run-id>/`.

Each run may produce:

- setup log
- per-suite raw logs
- JUnit XML for PHPUnit suites
- crawl artifacts for crawl plans
- JSON and Markdown summaries

Latest pointers are also maintained at summary scope, case scope, and optional context scope.
