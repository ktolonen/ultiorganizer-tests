# PoC Setup Plan

## Objective

Build a first runnable end-to-end validation path in containers for one customization and one configuration profile.

The PoC must prove that the harness can:

- boot Ultiorganizer in an isolated test environment
- run unit tests against selected `lib/` functions
- run integration tests against a disposable MariaDB
- request selected PHP pages and detect render/runtime failures
- emit machine-readable and human-readable results
- expose the same flow through an MCP interface for agent use

## Target PoC Case

- Customization: `cust/default`
- Configuration profile: `baseline`
- Database source: `/home/kari/code/ultiorganizer/sql/ultiorganizer.sql`
- Suites enabled: `unit`, `integration`, `smoke`

## Container Setup

### Services

- `php-test`: runs PHPUnit, smoke test scripts, and MCP server
- `mariadb`: disposable database for integration tests
- optional web-serving path inside `php-test` or a separate lightweight web service for page smoke requests

### Base assumptions

- PHP compatibility matches the app target: PHP 8.3
- MariaDB compatibility matches the app target: MariaDB 10.11
- all commands are runnable through Docker Compose

## Bootstrap Strategy

The harness must provide a controlled bootstrap that makes legacy `lib/` code safe to execute in tests.

Bootstrap responsibilities:

- set `$_SERVER['SERVER_NAME']` and related values expected by `/home/kari/code/ultiorganizer/lib/database.php`
- ensure includes resolve against the mounted SUT path
- point config loading to test-only config files
- fail fast if a non-test database target is detected
- isolate per-suite and per-case runtime settings

## Database Strategy

### Initialization

- create a fresh MariaDB schema for every run or every matrix case
- initialize schema from `/home/kari/code/ultiorganizer/sql/ultiorganizer.sql`
- load small fixture packs after schema setup instead of using one large shared dataset

### First fixture pack

Create one minimal fixture pack that supports:

- at least one DB-backed `lib/` read path
- at least one DB-backed `lib/` write or state-changing path if safe and representative
- at least one smoke-tested page that depends on initialized data

## First Test Targets

### Unit tests

Start with pure or low-coupled helper functions, for example from `lib/common.functions.php`.

Selection criteria:

- deterministic input/output
- no database connection required
- little or no dependence on session or HTTP state

### Integration tests

Start with one or two representative DB-backed functions under `lib/`.

Selection criteria:

- common production usage
- query behavior important enough to protect
- manageable fixture requirements

### Smoke tests

Start with a small allowlist of PHP pages and assert:

- HTTP request succeeds
- no fatal error output
- no PHP warning/error output in the response or logs

Smoke scope is intentionally shallow in the PoC.

## Commands to Provide

These commands should exist as normal script entrypoints before MCP wraps them:

- `test:unit`
- `test:integration`
- `test:smoke`
- `test:case baseline-default`
- `test:matrix`

## PoC Acceptance Criteria

The PoC is complete when:

- containers can be built and started from a clean checkout
- the SUT is mounted or cloned read-only
- one matrix case runs all three suites automatically
- the DB is disposable and recreated cleanly
- reports identify the case, suite, and test name
- the same case can be executed through MCP without bypassing the scripts
