# Reporting

Every run writes structured artifacts under `reports/` so failures can be inspected after the fact.

## Locations

- `reports/cases/<case-id>/<run-id>/`: one run
- `reports/summary/latest.json`, `reports/summary/latest-failed.json`: latest across all cases
- `reports/cases/<case-id>/latest.json`, `.../latest-failed.json`: latest per case
- `reports/{summary,cases/<case-id>}/contexts/<context-label>/`: the same pointers per context label
- `reports/index.html`: browser index built by `./report:html`

## Per-Run Artifacts

- `logs/`: `setup.log`, one log per suite, `coverage.log`, and `apache-error.log` (Apache/PHP error-log content written during the run)
- `junit/`: JUnit XML per PHPUnit suite
- `crawl/<plan-id>/`: crawl plan artifacts
- `coverage/`: merged coverage when `unit` or `integration` ran (see [PHPUnit Suites](phpunit.md#code-coverage))
- `summary/summary.json` (canonical) and `summary/summary.md`

## Summary Contents

Status, case id, requested suites, setup result, per-suite results, failure classification and reason, first failed test, failed smoke pages, crawl plan results, SUT git and PR context, artifact paths, and `runtime_logs.apache_error_log` (paths, whether new content or PHP issue patterns appeared, and an excerpt).

When a smoke or crawl failure is unclear, check the Apache error log from `./logs:case` or `artifact_paths.apache_error_log`.

## Failure Classes

| Class | Raised by |
|---|---|
| `preflight_failure` | SUT path checks |
| `container_startup_failure` | Compose startup |
| `runtime_sut_copy_config_failure` | Runtime copy or config generation |
| `database_initialization_failure` | DB readiness, recreate, schema load |
| `fixture_load_failure` | Fixture pack load |
| `php_lint_failure` | `lint` |
| `phpunit_test_failure` | `unit`, `integration`, `export`, `api` |
| `smoke_http_runtime_failure` | `smoke` |
| `crawl_runtime_failure` | `crawl` |

## Commands

- `./report:html [--output PATH]`: build the index from every `summary.json`
- `./report:clean --keep N` (default 20): delete older run directories, prune stale pointers, rebuild the index. Flags: `--dry-run`, `--case-id`, `--all`, `--no-html`.

## PHP Error-Log Gate

The test image logs PHP like a dev setup (`log_errors=On`, `error_reporting=E_ALL`, `error_log=/var/log/php/error.log`; `docker/php-test/logging.ini`). `UO_APACHE_ERROR_LOG` and the runner both point at that file. If a run is otherwise green but a line matching `config/php-issue-pattern.txt` (Fatal error, Parse error, Warning, Notice, Deprecated, Uncaught) was logged, the run fails with classification `php_runtime_issue`, and the matching lines appear in `summary.md` and `runtime_logs.apache_error_log.php_issue_lines`. The same pattern file feeds `tests/Support/PhpIssue.php` for the API, export and smoke tests. Rebuild the image after changing `logging.ini`.
