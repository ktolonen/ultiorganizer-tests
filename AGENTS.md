# AGENTS.md

Implementation summary for the current Ultiorganizer test harness.

## Repo purpose

- This repository is a separate Dockerized test harness for the production Ultiorganizer codebase, typically at sibling path `../ultiorganizer`.
- The SUT is mounted read-only into the test container and copied into `.runtime/cases/<case-id>/sut` before test config is injected.
- Every run recreates the disposable MariaDB test database and writes reports under `reports/`.

## Current implementation

- `docker-compose.yml`: starts `php-test` and `mariadb`.
- `docker/php-test/Dockerfile`: PHP 8.3 Apache image with mysqli, gettext, mbstring, Composer, Python, MariaDB client, `wget`, locales, and Apache access config for the runtime webroot.
- `config/matrix.json`: currently defines one default case, `baseline-default`, with `lint`, `unit`, `integration`, `export`, `api`, `smoke`, and `crawl` suites plus case-scoped smoke pages and crawl plans.
- `config/profiles/baseline.json`: baseline test config profile.
- `fixtures/baseline.sql`: deterministic fixture pack with one API-public season, one series, one visible pool, two teams, reservations/location rows, two pool games, minimal player/goal data, a deterministic superadmin account for authenticated crawl coverage, and a deterministic API token for REST API coverage.
- `scripts/harness.py`: host-side orchestration for preflight, `doctor`, `quick`, suites, cases, matrix runs, and report access.
- `scripts/container_runner.py`: container-side orchestration for runtime SUT copy, config generation, DB bootstrap, fixture loading, PHP lint execution, PHPUnit suite execution, crawl execution, and report writing.
- `tests/Unit`, `tests/Integration`, `tests/Export`, `tests/Api`, `tests/Smoke`: PHPUnit suites for helper functions, DB-backed season/team/pool/config reads, export endpoint contracts, REST API contracts, and HTTP page smoke checks.
- `tests/Js`: host-side Node tests for shipped client-side JavaScript (no DB, browser, or container). Currently covers the Timekeeper timer engine (`script/timekeeper.js`) under a DOM stub and a fake clock. Run with `./test:js`. See `docs/js-tests.md`.
- `mcp/server.py`: thin stdio JSON-RPC MCP wrapper over the normal script entrypoints.
- `docs/README.md`: documentation index for the topic-oriented docs under `docs/`, including the dedicated `docs/lint.md` syntax-lint note.

## Stable entrypoints

- `./doctor`
- `./test:quick`
- `./test:lint`
- `./test:unit`
- `./test:integration`
- `./test:export`
- `./test:api`
- `./test:smoke`
- `./test:crawl`
- `./test:js`
- `./test:case baseline-default`
- `./test:matrix`
- `./test:filter baseline-default <pattern>`
- `./report:latest`
- `./report:case baseline-default`
- `./report:html`
- `./report:clean`
- `./logs:case baseline-default`
- `./libtest:catalog-refresh`
- `./libtest:missing`
- `./libtest:run --lib-file <lib-file>`
- `./libtest:coverage --lib-file <lib-file>`
- `./libtest:scaffold --lib-file <lib-file>`
- `./libtest:triage-status`

## Runtime behavior

- The harness uses the sibling SUT path `../ultiorganizer` by default and also accepts `--sut-path` for worktrees or alternate checkouts.
- The generated test config sets `ALLOW_INSTALL=true` because Ultiorganizer refuses to boot through `index.php` while `install.php` exists otherwise.
- DB bootstrap uses MariaDB over plain TCP with SSL disabled for the local container network.
- Apache serves `/workspace/.runtime/webroot`, which is a symlink to the prepared runtime SUT copy for the active case.
- `./doctor` checks SUT preflight, Docker access, Compose services, stack startup, and MariaDB connectivity from `php-test`.
- `./test:quick` runs `lint`, `unit`, and `integration` for `baseline-default` and is the default day-to-day command.
- `lint` runs `php -l` across PHP files in the prepared runtime SUT copy.
- `export` checks fixture-backed `ext/` CSV, JSON, XML, and RSS contracts over HTTP.
- `api` checks the versioned `/api/v1` JSON API, including OpenAPI, token failures, and fixture-backed authenticated reads.
- `crawl` is configured per case with declarative `crawl_plans` and currently supports `follow_links`, `php_files`, and `path_probes`.

## Code coverage

- Coverage is collected with PCOV (installed in the `php-test` image, disabled by default) and opted in per invocation only for the in-process `unit` and `integration` suites; the HTTP-driven `export`, `api`, `smoke`, and `crawl` suites yield no coverage.
- Coverage scope is the SUT's `lib/` tree minus the vendored third-party directories, configured via the `<source>` block in `phpunit.xml.dist`. Code exercised outside `lib/` (page entrypoints, `localization.php`, etc.) does not appear.
- Each in-process suite writes a raw `<suite>.cov`, then `phpcov` merges them into `coverage/` under the run directory: `coverage/html/index.html` (per-file browsable), `coverage/clover.xml` (per-file line data, absolute container paths), `coverage/coverage.txt` (overall summary only), and `coverage/coverage.json` (percent consumed by `report:html`).
- `coverage/` is wiped at the start of each run, so consulting coverage means rerunning the relevant suite first rather than trusting a stale directory.
- Overall line coverage is low by design; per-file lib testing aims for local depth on the file under test, not a rising global percentage.
- When authoring tests, use coverage to find uncovered branches in the target `lib/*.php` file. See `docs/phpunit.md` for the coverage details and `docs/ai/use-coverage-for-tests/SKILL.md` for the authoring workflow.

## Reporting

- Canonical run artifacts live under `reports/cases/<case-id>/<run-id>/`.
- `reports/summary/latest.json` stores the latest run summary.
- `reports/cases/<case-id>/latest.json` stores the latest summary for a specific case.
- Failed runs also refresh `reports/summary/latest-failed.json` and `reports/cases/<case-id>/latest-failed.json`.
- Each run writes a setup log, raw suite logs, JUnit XML for PHPUnit suites, and crawl artifacts where relevant.
- Summaries include failure classification, failure reason, first failed test, failed smoke pages, crawl plan results, and direct artifact paths.
- Summaries also include SUT context metadata such as branch, commit, dirty state, inferred or explicit context label, and optional PR number metadata.
- Context-scoped latest pointers are written under `reports/summary/contexts/<context-label>/` and `reports/cases/<case-id>/contexts/<context-label>/`.
- Current failure classes used by the harness are:
  - `preflight_failure`
  - `container_startup_failure`
  - `runtime_sut_copy_config_failure`
  - `database_initialization_failure`
  - `fixture_load_failure`
  - `php_lint_failure`
  - `phpunit_test_failure`
  - `smoke_http_runtime_failure`
  - `crawl_runtime_failure`

## MCP notes

- The MCP wrapper exposes `matrix_list`, `matrix_run`, `suite_run`, `test_run`, `report_latest`, `report_case`, and `logs_case`.
- MCP must stay a thin wrapper over the existing harness scripts; do not duplicate orchestration logic in `mcp/server.py`.
- MCP tool responses inherit the same structured payloads as the CLI paths, including failure classification and artifact paths.

## Documentation notes

- Prefer short topic-oriented Markdown documents under `docs/` instead of growing one long design note.
- Use `docs/README.md` as the landing page for repository documentation.
- When behavior changes materially, update the relevant topic docs and keep the top-level `README.md` as a concise entrypoint rather than the only source of detail.

## Working rules for future changes

- Keep the SUT read-only from the harness perspective. Test-only config belongs in the runtime copy, not in the production repo.
- Add new cases in `config/matrix.json` and new profile data in `config/profiles/` instead of hardcoding more branches into the scripts.
- Prefer extending the existing Python orchestration instead of replacing it with ad hoc shell logic.
- Keep generated artifacts out of git; do not hand-edit `.runtime/` or `reports/`.
